<?php

use App\Dav\Exceptions\ImportException;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

// A Nextcloud-style remote: discovery on the principal, a PROPFIND listing on the home set and a REPORT per collection.
/** @param  array<string, array<string, string>>  $collections  name => [object => data] */
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

    it('reports the totals of a multi-calendar import', function () {
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
        $import->selectCalendars($user, $import->getState($user->id), ['Personal', 'Work']);
        $import->importCalendars($user, $import->getState($user->id));
        $import->importEvents($user, $import->getState($user->id));

        $state = $import->getState($user->id);

        expect($state->stage)->toBe('finished')
            ->and($state->eventsCount)->toBe(3)
            ->and($state->eventsDone)->toBe(3)
            ->and($state->eventsSkipped)->toBe(0);
    });

    it('stores the remote events as they are and skips the ones it cannot store', function () {
        $user = davUser();
        $series = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nextcloud//EN', 'METHOD:PUBLISH',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'END:VTIMEZONE',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'DTSTART;TZID=Europe/Berlin:20301014T090000', 'DTEND;TZID=Europe/Berlin:20301014T091500',
            'RRULE:FREQ=DAILY;COUNT=5', 'EXDATE;TZID=Europe/Berlin:20301015T090000', 'SUMMARY:Standup', 'ATTENDEE;CN=Jane:mailto:jane@example.org',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT10M', 'END:VALARM', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID;TZID=Europe/Berlin:20301016T090000',
            'DTSTART;TZID=Europe/Berlin:20301016T110000', 'DTEND;TZID=Europe/Berlin:20301016T111500', 'SUMMARY:Standup (moved)', 'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";
        fakeRemoteDav('calendars', ['Personal' => [
            'series.ics' => $series,
            'broken.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:broken\r\nSUMMARY:No start\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            'garbage.ics' => 'not a calendar',
        ]]);
        $import = app(DavService::class)->import()->calendars();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['Personal']);
        $import->importCalendars($user, $import->getState($user->id));
        $import->importEvents($user, $import->getState($user->id));

        $calendar = app(DavService::class)->calendars()->getByName($user, 'Personal');
        $stored = DB::connection('pgsql')->table('calendarobjects')->where('calendarid', $calendar->calendarId)->pluck('calendardata')
            ->map(fn ($data) => is_resource($data) ? stream_get_contents($data) : $data);
        $state = $import->getState($user->id);

        expect($state->eventsCount)->toBe(3)
            ->and($state->eventsDone)->toBe(1)
            ->and($state->eventsSkipped)->toBe(2)
            ->and($stored)->toHaveCount(1)
            ->and($stored[0])->toContain('DTSTART;TZID=Europe/Berlin:20301014T090000', 'BEGIN:VTIMEZONE', 'EXDATE;TZID=Europe/Berlin:20301015T090000')
            ->toContain('RECURRENCE-ID;TZID=Europe/Berlin:20301016T090000', 'BEGIN:VALARM', 'ATTENDEE;CN=Jane:mailto:jane@example.org')
            ->not->toContain('METHOD:');
    });

    it('loads events with its own limits, not the tighter external calendar ones', function () {
        // Small enough to refuse the REPORT below, large enough for the discovery responses.
        config(['calendars.external.max_response_bytes' => 2000]);
        $user = davUser();
        fakeRemoteDav('calendars', [
            'Personal' => ['a.ics' => davIcs('a', str_repeat('Long history ', 400))],
        ]);
        $import = app(DavService::class)->import()->calendars();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['Personal']);
        $import->importCalendars($user, $import->getState($user->id));
        $import->importEvents($user, $import->getState($user->id));

        $calendar = app(DavService::class)->calendars()->getByName($user, 'Personal');

        expect($import->getState($user->id)->eventsDone)->toBe(1)
            ->and(app(DavService::class)->calendars()->events()->list($calendar))->toHaveCount(1);
    });

    it('shows the imported events in the cached listing once the import cleared the cache', function () {
        $user = davUser();
        fakeRemoteDav('calendars', [
            'Personal' => [
                'a.ics' => davIcs('a', 'Dentist', '20301015T100000Z', '20301015T110000Z'),
                'b.ics' => davIcs('b', 'Gym', '20301016T180000Z', '20301016T190000Z'),
            ],
        ]);
        $dav = app(DavService::class);
        $import = $dav->import()->calendars();
        $titles = fn () => collect(app(\App\Api\Calendars\Services\CalendarService::class)->listEvents($user, '2030-10')->events)->pluck('title')->sort()->values()->all();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['Personal']);
        $import->importCalendars($user, $import->getState($user->id));
        // An empty calendar isn't cached, so the new one gets an event before it is read.
        $personal = $dav->calendars()->getByName($user, 'Personal');
        $dav->calendars()->events()->create($personal, \App\Dav\DTOs\EventDTO::fromRequest([
            'title' => 'Early', 'start_date' => '2030-10-14 10:00:00', 'end_date' => '2030-10-14 11:00:00', 'is_all_day' => false,
        ], $personal, $user));
        expect($titles())->toBe(['Early']);

        $import->importEvents($user, $import->getState($user->id));
        expect($titles())->toBe(['Early']);
        $import->clearCache($user);

        expect($titles())->toBe(['Dentist', 'Early', 'Gym']);
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

    it('treats a session started before the secret was encrypted as expired', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => []]);
        $import = app(DavService::class)->import()->calendars();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $state = $import->getState($user->id);
        $state->secret = 'app-password';
        Cache::set('calendar-import-'.$user->id, $state, now()->addMinutes(60));

        expect($import->getState($user->id))->toBeNull()
            ->and(Cache::has('calendar-import-'.$user->id))->toBeFalse();

        Cache::set('calendar-import-'.$user->id, $state, now()->addMinutes(60));
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars/import/select', ['calendars' => ['Personal']]);
        expectApiError($response, 404);
        $response->assertJsonPath('message', 'Import state not found');
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
        DB::connection('pgsql')->statement("CREATE TRIGGER fail_personal BEFORE INSERT ON calendarinstances WHEN NEW.displayname = 'Personal' BEGIN SELECT RAISE(ABORT, 'insert failed'); END");
        $this->beforeApplicationDestroyed(fn () => DB::connection('pgsql')->statement('DROP TRIGGER IF EXISTS fail_personal'));

        Log::shouldReceive('channel')->with('dav')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Calendar import skipped a calendar', Mockery::on(
            fn (array $context) => $context['user_id'] === $user->id && $context['calendar'] === 'Personal' && ! str_contains(json_encode($context), 'app-password')
        ));

        $import->importCalendars($user, $import->getState($user->id));

        expect($import->getState($user->id)->calendarsDone)->toBe(0);
    });

    it('follows a redirect from a calendar collection', function () {
        $user = davUser();
        Http::fake(fn (Request $request) => $request->method() === 'REPORT' && str_starts_with($request->url(), 'https://remote.example.com/')
            ? Http::response('', 301, ['Location' => 'https://moved.example.com/remote.php/dav/calendars/alice/personal/'])
            : null);
        fakeRemoteDav('calendars', ['Personal' => ['a.ics' => davIcs('a', 'Dentist')]]);
        $import = app(DavService::class)->import()->calendars();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['Personal']);
        $import->importCalendars($user, $import->getState($user->id));
        $import->importEvents($user, $import->getState($user->id));

        expect($import->getState($user->id)->eventsDone)->toBe(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'REPORT' && str_starts_with($request->url(), 'https://moved.example.com/'));
    });

    it('logs a calendar it cannot read and imports the others', function () {
        $user = davUser();
        Http::fake(fn (Request $request) => $request->method() === 'REPORT' && str_ends_with($request->url(), '/personal/')
            ? Http::response('', 500)
            : null);
        fakeRemoteDav('calendars', ['Personal' => ['a.ics' => davIcs('a')], 'Work' => ['b.ics' => davIcs('b', 'Review')]]);
        $import = app(DavService::class)->import()->calendars();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectCalendars($user, $import->getState($user->id), ['Personal', 'Work']);
        $import->importCalendars($user, $import->getState($user->id));

        Log::shouldReceive('channel')->with('dav')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Calendar import could not read a calendar', Mockery::on(
            fn (array $context) => $context === ['host' => 'remote.example.com', 'status' => 500]
        ));

        $import->importEvents($user, $import->getState($user->id));

        $work = app(DavService::class)->calendars()->getByName($user, 'Work');
        expect($import->getState($user->id)->stage)->toBe('finished')
            ->and(collect(app(DavService::class)->calendars()->events()->list($work))->pluck('title')->all())->toBe(['Review'])
            ->and(app(DavService::class)->calendars()->events()->list(app(DavService::class)->calendars()->getByName($user, 'Personal')))->toBeEmpty();
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

describe('Calendar discovery', function () {
    it('finds calendars through .well-known, the current user principal and the home set', function () {
        $user = davUser();
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                $url === 'https://cal.example.org/.well-known/caldav' => Http::response('', 301, ['Location' => '/dav/']),
                $url === 'https://cal.example.org/dav/' => Http::response(multistatus(remoteResponse('/dav/', '<d:current-user-principal><d:href>/dav/principals/bob/</d:href></d:current-user-principal>')), 207),
                $url === 'https://cal.example.org/dav/principals/bob/' => Http::response(multistatus(remoteResponse('/dav/principals/bob/', '<cal:calendar-home-set><d:href>/dav/cal/bob/</d:href></cal:calendar-home-set>')), 207),
                $url === 'https://cal.example.org/dav/cal/bob/' => Http::response(multistatus(
                    remoteResponse('/dav/cal/bob/', '<d:resourcetype><d:collection/></d:resourcetype>')
                    .remoteResponse('/dav/cal/bob/home/', '<d:displayname>Home</d:displayname><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>')
                ), 207),
                default => Http::response('', 404),
            };
        });

        $import = app(DavService::class)->import()->calendars();
        $import->start($user, 'https://cal.example.org', 'bob', 'app-password');

        expect($import->getState($user->id)->calendars)->toBe([
            ['url' => 'https://cal.example.org/dav/cal/bob/home/', 'name' => 'Home', 'color' => '#e5e7eb'],
        ]);
    });

    it('keeps the app password out of the cached import state in plaintext', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => []]);

        $import = app(DavService::class)->import()->calendars();
        $import->start($user, REMOTE, 'alice', 'app-password');

        expect(serialize($import->getState($user->id)))->not->toContain('app-password');
    });

    it('refuses private and loopback servers', function (string $url) {
        $user = davUser();
        Http::fake();

        try {
            app(DavService::class)->import()->calendars()->start($user, $url, 'alice', 'app-password');
        } finally {
            Http::assertNothingSent();
        }
    })->with([
        'loopback' => 'http://127.0.0.1/dav/',
        'private' => 'https://10.0.0.5/dav/',
        'link-local' => 'http://169.254.169.254/latest/',
        'ipv6 loopback' => 'http://[::1]/dav/',
    ])->throws(ImportException::class);

    it('marks a calendar import failed when the remote principal cannot be read', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => []], discoveryStatus: 401);
        $import = app(DavService::class)->import()->calendars();

        expect(fn () => $import->start($user, REMOTE, 'alice', 'wrong'))->toThrow(ImportException::class);

        expect($import->getState($user->id)->stage)->toBe('failed');
    });

    it('marks a calendar import failed when the account has no calendars', function () {
        $user = davUser();
        fakeRemoteDav('calendars', [], 207);
        $import = app(DavService::class)->import()->calendars();

        expect(fn () => $import->start($user, REMOTE, 'alice', 'app-password'))->toThrow(ImportException::class);

        expect($import->getState($user->id)->stage)->toBe('failed');
    });

    it('marks a calendar job failed when it fails', function () {
        $user = davUser();
        fakeRemoteDav('calendars', ['Personal' => []]);
        $import = app(DavService::class)->import()->calendars();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $jobId = $import->getState($user->id)->jobId;

        (new \App\Api\Calendars\Jobs\ImportCalendars($user->id, $jobId))->failed(new \RuntimeException('boom'));

        expect($import->getState($user->id)->stage)->toBe('failed');
    });
});

describe('Contact import', function () {
    it('logs an address book it has to skip', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => []]);
        $books = Mockery::mock(app(DavService::class)->addressBooks());
        $books->shouldReceive('create')->andThrow(new \RuntimeException('Address book is broken'));
        $import = new \App\Dav\Services\ContactImport($books, app(\App\Shared\Services\UserCacheService::class));

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);

        Log::shouldReceive('channel')->with('dav')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Contact import skipped an address book', Mockery::on(
            fn (array $context) => $context['user_id'] === $user->id && $context['address_book'] === 'Friends'
        ));

        $import->importAddressBooks($user, $import->getState($user->id));

        expect($import->getState($user->id)->addressBooksDone)->toBe(0);
    });

    it('clears the contact cache when the import fails', function () {
        $user = davUser();
        $dav = app(DavService::class);
        fakeRemoteDav('addressbooks', ['Friends' => []]);
        $import = $dav->import()->addressBooks();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $jobId = $import->getState($user->id)->jobId;

        $book = $dav->addressBooks()->create($user, new \App\Dav\DTOs\AddressBookDTO(
            id: 0, principalUri: 'principals/'.$user->email, uri: 'friends', displayName: 'Friends', description: null, color: null,
        ));
        $contacts = app(\App\Api\Contacts\Services\ContactService::class);
        expect($contacts->listContacts($user))->toBe([]);
        $dav->addressBooks()->contacts()->create($book, \App\Dav\DTOs\ContactDTO::fromRequest(['first_name' => 'Partial', 'last_name' => 'Contact'], $book));

        $import->fail($user->id, $jobId);

        expect(collect($contacts->listContacts($user))->pluck('fullName')->all())->toBe(['Partial Contact']);
    });

    it('shows the imported contacts in the cached listing once the import cleared the cache', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', [
            'Friends' => ['a.vcf' => davVcard('a', 'Jane Doe'), 'b.vcf' => davVcard('b', 'John Doe')],
        ]);
        $dav = app(DavService::class);
        $import = $dav->import()->addressBooks();
        $names = fn () => collect(app(\App\Api\Contacts\Services\ContactService::class)->listContacts($user))->pluck('fullName')->sort()->values()->all();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);
        $import->importAddressBooks($user, $import->getState($user->id));
        // An empty address book isn't cached, so the new one gets a contact before it is read.
        $friends = $dav->addressBooks()->getByName($user, 'Friends');
        $dav->addressBooks()->contacts()->create($friends, \App\Dav\DTOs\ContactDTO::fromRequest(['first_name' => 'Early', 'last_name' => 'Bird'], $friends));
        expect($names())->toBe(['Early Bird']);

        $import->importContacts($user, $import->getState($user->id));
        expect($names())->toBe(['Early Bird']);
        $import->clearCache($user);

        expect($names())->toBe(['Early Bird', 'Jane Doe', 'John Doe']);
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

    it('reports the totals of a multi-book import', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', [
            'Friends' => [
                'a.vcf' => davVcard('a', 'Jane Doe'),
                'b.vcf' => davVcard('b', 'John Doe'),
            ],
            'Work' => ['c.vcf' => davVcard('c', 'Boss')],
        ]);
        $import = app(DavService::class)->import()->addressBooks();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends', 'Work']);
        $import->importAddressBooks($user, $import->getState($user->id));
        $import->importContacts($user, $import->getState($user->id));

        $state = $import->getState($user->id);

        expect($state->stage)->toBe('finished')
            ->and($state->contactsCount)->toBe(3)
            ->and($state->contactsDone)->toBe(3)
            ->and($state->contactsSkipped)->toBe(0);
    });

    it('checks every remote card: skips an unreadable one, repairs a missing UID and converts vCard 2.1', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', [
            'Friends' => [
                'a.vcf' => davVcard('a', 'Jane Doe'),
                'broken.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:broken\r\nFN:Broken\r\nitem_1.EMAIL:broken@example.com\r\nEND:VCARD\r\n",
                'no-uid.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Uid\r\nEND:VCARD\r\n",
                'old.vcf' => "BEGIN:VCARD\r\nVERSION:2.1\r\nN:Smith;Old;;;\r\nFN:Old Smith\r\nTEL;CELL:+15550100\r\nEND:VCARD\r\n",
            ],
        ]);
        $import = app(DavService::class)->import()->addressBooks();

        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);
        $import->importAddressBooks($user, $import->getState($user->id));
        $import->importContacts($user, $import->getState($user->id));

        $addressBook = app(DavService::class)->addressBooks()->getByName($user, 'Friends');
        $cards = collect(app(DavService::class)->addressBooks()->contacts()->listStored($addressBook))
            ->map(fn ($contact) => \Sabre\VObject\Reader::read($contact->storedData))
            ->keyBy(fn ($vCard) => (string) $vCard->FN);

        expect($cards->keys()->sort()->values()->all())->toBe(['Jane Doe', 'No Uid', 'Old Smith'])
            ->and((string) $cards['No Uid']->UID)->not->toBe('')
            ->and((string) $cards['Old Smith']->VERSION)->toBe('3.0')
            ->and($import->getState($user->id))->toMatchObject(['stage' => 'finished', 'contactsCount' => 4, 'contactsDone' => 3, 'contactsSkipped' => 1]);
    });

    it('answers 409 to a second start and to select while an import runs', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => []]);
        $import = app(DavService::class)->import()->addressBooks();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);

        expect(fn () => $import->start($user, REMOTE, 'alice', 'app-password'))->toThrow(\App\Dav\Exceptions\ImportConflictException::class)
            ->and(fn () => $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']))->toThrow(\App\Dav\Exceptions\ImportConflictException::class);
    });

    it('does not select address books for a file import', function () {
        $user = davUser();
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Queue::fake();
        $this->actingAs($user, 'sanctum')->post('/api/v1/address-books/import/file', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('friends.vcf', davVcard('a')),
        ], ['Accept' => 'application/json'])->assertStatus(200);
        $this->travel(6)->minutes();

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/address-books/import/select', ['address_books' => ['Friends']]), 404);
    });

    it('reports the source and skipped contacts in the import state', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => []]);
        app(DavService::class)->import()->addressBooks()->start($user, REMOTE, 'alice', 'app-password');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/address-books/import/state')
            ->assertStatus(200)
            ->assertJsonPath('data.stage', 'select')
            ->assertJsonPath('data.source', 'carddav')
            ->assertJsonPath('data.contacts_skipped', 0)
            ->assertJsonPath('data.address_books', ['Friends']);
    });

    it('marks its own import failed when the job fails and leaves another import alone', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => []]);
        $import = app(DavService::class)->import()->addressBooks();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);
        $jobId = $import->getState($user->id)->jobId;

        (new \App\Api\Contacts\Jobs\ImportContacts($user->id, 'an-older-job'))->failed(null);
        expect($import->getState($user->id)->stage)->toBe('address-books');

        (new \App\Api\Contacts\Jobs\ImportContacts($user->id, $jobId))->failed(null);
        expect($import->getState($user->id)->stage)->toBe('failed');
    });

    it('runs only the import it was dispatched for', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => ['a.vcf' => davVcard('a', 'Jane Doe')]]);
        $import = app(DavService::class)->import()->addressBooks();
        $import->start($user, REMOTE, 'alice', 'app-password');
        $import->selectAddressBooks($user, $import->getState($user->id), ['Friends']);

        (new \App\Api\Contacts\Jobs\ImportContacts($user->id, 'an-older-job'))->handle(app(DavService::class));
        expect($import->getState($user->id)->stage)->toBe('address-books');

        (new \App\Api\Contacts\Jobs\ImportContacts($user->id, $import->getState($user->id)->jobId))->handle(app(DavService::class));
        expect($import->getState($user->id))->toMatchObject(['stage' => 'finished', 'contactsDone' => 1]);
    });

    it('fails and leaves the import failed when the remote principal cannot be read', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', ['Friends' => []], discoveryStatus: 401);
        $import = app(DavService::class)->import()->addressBooks();

        expect(fn () => $import->start($user, REMOTE, 'alice', 'wrong'))->toThrow(ImportException::class);

        expect($import->getState($user->id)->stage)->toBe('failed');
    });

    it('fails and leaves the import failed when the account has no address books', function () {
        $user = davUser();
        fakeRemoteDav('addressbooks', [], 207);
        $import = app(DavService::class)->import()->addressBooks();

        expect(fn () => $import->start($user, REMOTE, 'alice', 'app-password'))->toThrow(ImportException::class);

        expect($import->getState($user->id)->stage)->toBe('failed');
    });
});

describe('Starting an import that cannot read the remote account', function () {
    it('answers 404 with a fixed message and logs the host only', function (string $kind, string $endpoint, int $discoveryStatus, string $message, string $logMessage) {
        $this->freezeTime();
        $user = davUser();
        fakeRemoteDav($kind, [], $discoveryStatus);
        $channel = Mockery::spy();
        Log::shouldReceive('channel')->andReturn($channel);

        $response = $this->actingAs($user, 'sanctum')->postJson($endpoint, [
            'url' => REMOTE,
            'username' => 'alice',
            'secret' => 'app-password',
        ]);

        expectApiError($response, 404);
        expect($response->getContent())->toBe(json_encode(['success' => false, 'message' => $message, 'timestamp' => now()->toISOString()]));
        $channel->shouldHaveReceived('warning')->with($logMessage, ['host' => 'remote.example.com'])->once();
    })->with([
        'calendars, principal unreadable' => ['calendars', '/api/v1/calendars/import', 401, 'Could not grab calendars', 'Calendar import could not reach the server'],
        'calendars, none found' => ['calendars', '/api/v1/calendars/import', 207, 'Could not grab calendars', 'Calendar import could not reach the server'],
        'address books, principal unreadable' => ['addressbooks', '/api/v1/address-books/import', 401, 'Could not grab address books', 'Contact import could not reach the server'],
        'address books, none found' => ['addressbooks', '/api/v1/address-books/import', 207, 'Could not grab address books', 'Contact import could not reach the server'],
    ]);
});
