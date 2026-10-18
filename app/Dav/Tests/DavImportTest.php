<?php

use App\Dav\Exceptions\ImportException;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

const REMOTE = 'https://remote.example.com/remote.php/dav/';

function multistatus(string $responses, string $namespaces = ''): string
{
    return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"'
        .' xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:apple="http://apple.com/ns/ical/"'.$namespaces.'>'
        .$responses.'</d:multistatus>';
}

function remoteResponse(string $href, string $props): string
{
    return "<d:response><d:href>{$href}</d:href><d:propstat><d:prop>{$props}</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>";
}

/**
 * Fake a Nextcloud-style remote DAV server: discovery on the principal,
 * a PROPFIND listing on the home set and a REPORT per collection.
 *
 * @param  array<string, array<string, string>>  $collections  name => [object => data]
 */
function fakeRemoteDav(string $kind, array $collections, int $discoveryStatus = 207): void
{
    $home = $kind === 'calendars' ? '/remote.php/dav/calendars/alice/' : '/remote.php/dav/addressbooks/users/alice/';
    $homeProp = $kind === 'calendars' ? 'cal:calendar-home-set' : 'card:addressbook-home-set';

    Http::fake(function (Request $request) use ($kind, $collections, $home, $homeProp, $discoveryStatus) {
        $url = $request->url();

        if (str_ends_with($url, '/principals/users/alice/')) {
            return Http::response(multistatus(remoteResponse('/remote.php/dav/principals/users/alice/', "<{$homeProp}><d:href>{$home}</d:href></{$homeProp}>")), $discoveryStatus);
        }

        if ($request->method() === 'PROPFIND' && str_ends_with($url, $home)) {
            $listing = collect(array_keys($collections))->map(fn (string $name) => remoteResponse(
                $home.strtolower($name).'/',
                $kind === 'calendars'
                    ? "<d:displayname>{$name}</d:displayname><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><apple:calendar-color>#FF0000</apple:calendar-color>"
                    : "<d:displayname>{$name}</d:displayname><d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>"
            ))->implode('');

            return Http::response(multistatus($listing), 207);
        }

        foreach ($collections as $name => $objects) {
            if ($request->method() === 'REPORT' && str_ends_with($url, $home.strtolower($name).'/')) {
                $dataProp = $kind === 'calendars' ? 'cal:calendar-data' : 'card:address-data';
                $entries = collect($objects)->map(fn (string $data, string $object) => remoteResponse(
                    $home.strtolower($name).'/'.$object,
                    '<d:getetag>"'.md5($data).'"</d:getetag>'."<{$dataProp}>".htmlspecialchars($data)."</{$dataProp}>"
                ))->implode('');

                return Http::response(multistatus($entries), 207);
            }
        }

        return Http::response('', 404);
    });
}

describe('Calendar import', function () {
    it('imports the selected calendar with its events', function () {
        $user = davUser();
        fakeRemoteDav('calendars', [
            'Personal' => [
                'a.ics' => davIcs('a', 'Dentist', '20301015T100000Z', '20301015T110000Z'),
                'b.ics' => davIcs('b', 'Gym', '20301016T180000Z', '20301016T190000Z'),
            ],
            'Work' => ['c.ics' => davIcs('c', 'Review')],
        ]);
        $import = app(DavService::class)->import()->calendars();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $state = $import->getState($user->id);
        $import->selectCalendars($user, $state, ['Personal']);
        $import->importCalendars($user, $import->getState($user->id));
        $import->importEvents($user, $import->getState($user->id));

        $state = $import->getState($user->id);
        $calendar = app(DavService::class)->calendars()->getByName($user, 'Personal');
        $titles = collect(app(DavService::class)->calendars()->events()->list($calendar))->pluck('title')->sort()->values();

        expect($state->stage)->toBe('finished')
            ->and($state->calendarsDone)->toBe(1)
            ->and($state->eventsCount)->toBe(2)
            ->and($state->eventsDone)->toBe(2)
            ->and($titles->all())->toBe(['Dentist', 'Gym'])
            ->and(app(DavService::class)->calendars()->getByName($user, 'Work'))->toBeNull();
    });

    it('lists the remote calendars for selection', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => [], 'Work' => []]);
        $import = app(DavService::class)->import()->calendars();

        $import->start($user, REMOTE, 'alice', 'app-password');

        $state = $import->getState($user->id);
        expect($state->stage)->toBe('select')
            ->and(collect($state->calendars)->pluck('name')->all())->toBe(['Personal', 'Work']);
    });

    it('authenticates against the remote server with the given credentials', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => []]);

        app(DavService::class)->import()->calendars()->start($user, REMOTE, 'alice', 'app-password');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('alice:app-password')));
    });

    it('renames a selected calendar whose name already exists', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['My Calendar' => []]);
        $import = app(DavService::class)->import()->calendars();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['My Calendar']);
        $import->importCalendars($user, $import->getState($user->id));

        expect(app(DavService::class)->calendars()->getByName($user, 'My Calendar (1)'))->not->toBeNull();
    });

    it('fails when the remote principal cannot be read', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => []], discoveryStatus: 401);

        app(DavService::class)->import()->calendars()->start($user, REMOTE, 'alice', 'wrong');
    })->throws(ImportException::class);

    it('fails when the remote account has no calendars', function () {
        $user = davUser();
        fakeRemoteDav('calendars', []);

        app(DavService::class)->import()->calendars()->start($user, REMOTE, 'alice', 'app-password');
    })->throws(ImportException::class);

    it('logs a calendar it has to skip', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => []]);
        $import = app(DavService::class)->import()->calendars();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['Personal']);
        app(DavService::class)->calendars()->create($user, \App\Dav\DTOs\CalendarDTO::fromRequest(['name' => 'Personal']));

        Log::shouldReceive('channel')->with('dav')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Calendar import skipped a calendar', Mockery::on(
            fn (array $context) => $context['user_id'] === $user->id && $context['calendar'] === 'Personal' && ! str_contains(json_encode($context), 'app-password')
        ));

        $import->importCalendars($user, $import->getState($user->id));

        expect($import->getState($user->id)->calendarsDone)->toBe(0);
    });

    it('imports events with escaped text without losing them', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => [
            'a.ics' => davIcs('a', 'Lunch\, then walk', '20301015T100000Z', '20301015T110000Z', ['LOCATION:Berlin\, Germany', 'DESCRIPTION:One\nTwo']),
        ]]);
        $import = app(DavService::class)->import()->calendars();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['Personal']);
        $import->importCalendars($user, $import->getState($user->id));
        $import->importEvents($user, $import->getState($user->id));

        expect($import->getState($user->id)->eventsDone)->toBe(1);
    });
});

describe('Contact import', function () {
    it('logs an address book it has to skip', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => []]);
        $import = app(DavService::class)->import()->addressBooks();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);
        app(DavService::class)->addressBooks()->create($user, new \App\Dav\DTOs\AddressBookDTO(
            id: 0, principalUri: 'principals/'.$user->email, uri: 'friends', displayName: 'Friends', description: null, color: null,
        ));

        Log::shouldReceive('channel')->with('dav')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Contact import skipped an address book', Mockery::on(
            fn (array $context) => $context['user_id'] === $user->id && $context['address_book'] === 'Friends'
        ));

        $import->importAddressBooks($user, $import->getState($user->id));

        expect($import->getState($user->id)->addressBooksDone)->toBe(0);
    });

    it('imports the selected address book with its contacts', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', [
            'Friends' => [
                'a.vcf' => davVcard('a', 'Jane Doe'),
                'b.vcf' => davVcard('b', 'John Doe'),
            ],
        ]);
        $import = app(DavService::class)->import()->addressBooks();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);
        $import->importAddressBooks($user, $import->getState($user->id));
        $import->importContacts($user, $import->getState($user->id));

        $state = $import->getState($user->id);
        $addressBook = app(DavService::class)->addressBooks()->getByName($user, 'Friends');
        $names = collect(app(DavService::class)->addressBooks()->contacts()->list($addressBook))->pluck('fullName')->sort()->values();

        expect($state->stage)->toBe('finished')
            ->and($state->contactsCount)->toBe(2)
            ->and($state->contactsDone)->toBe(2)
            ->and($names->all())->toBe(['Jane Doe', 'John Doe']);
    });

    it('fails when the remote principal cannot be read', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => []], discoveryStatus: 401);

        app(DavService::class)->import()->addressBooks()->start($user, REMOTE, 'alice', 'wrong');
    })->throws(ImportException::class);
});
