<?php

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\ContactDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Factories\DavServerFactory;
use App\Dav\Services\CalendarSharing;
use App\Dav\Services\DavService;
use App\Dav\Services\VCardPhotoProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('DavService', function () {
    it('exposes calendars, address books and principals', function () {
        $service = app(DavService::class);

        expect($service->calendars())->not->toBeNull();
        expect($service->addressBooks())->not->toBeNull();
        expect($service->principals())->not->toBeNull();
    });
});

describe('DAV calendars', function () {
    it('creates a default calendar per user', function () {
        $user = makeUser();

        $calendars = app(DavService::class)->calendars()->list($user);

        expect($calendars)->not->toBeEmpty();
        expect($calendars[0]->displayName)->toBe('My Calendar');
    });

    it('creates a named calendar and finds it by name', function () {
        $user = makeUser();
        $calendars = app(DavService::class)->calendars();

        $dto = new CalendarDTO(0, 0, 'Work', 'Work', '#FF0000', null, null, false);
        $created = $calendars->create($user, $dto);

        $found = $calendars->getByName($user, 'Work');
        expect($found)->not->toBeNull();
        expect($found->displayName)->toBe('Work');
    });

    it('creates and lists events', function () {
        $user = makeUser();
        $calendar = app(DavService::class)->calendars()->list($user)[0];

        $dto = EventDTO::fromRequest([
            'title' => 'Standup',
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
        ], $calendar, $user);

        $event = app(DavService::class)->calendars()->events()->create($calendar, $dto);

        expect($event)->not->toBeNull();
        expect($event->title)->toBe('Standup');

        $events = app(DavService::class)->calendars()->events()->list($calendar);
        expect($events)->not->toBeEmpty();
    });
});

describe('DAV address books and contacts', function () {
    it('creates a default address book per user', function () {
        $user = makeUser();

        $books = app(DavService::class)->addressBooks()->list($user);

        expect($books)->not->toBeEmpty();
    });

    it('creates a contact in an address book', function () {
        $user = makeUser();
        $addressBook = app(DavService::class)->addressBooks()->list($user)[0];

        $dto = new ContactDTO(
            uid: null,
            firstName: 'John',
            middleName: null,
            lastName: 'Doe',
            fullName: 'John Doe',
            email: json_encode([['value' => 'john@example.com', 'type' => 'INTERNET']]),
            phone: null,
            organization: null,
            street: null,
            city: null,
            state: null,
            postalCode: null,
            country: null,
            photo: null,
            groups: null,
            prefix: null,
            suffix: null,
            note: null,
            uri: null,
            etag: null,
            addressBookId: null,
        );

        $contact = app(DavService::class)->addressBooks()->contacts()->create($addressBook, $dto);

        expect($contact)->not->toBeNull();
        expect($contact->fullName)->toBe('John Doe');
    });
});

describe('DAV principals', function () {
    it('creates and lists principals', function () {
        $user = makeUser();
        $principals = app(DavService::class)->principals();

        $uri = $principals->create($user->email);

        expect($uri)->toBe('principals/'.$user->email);

        $all = $principals->list();
        expect(collect($all)->pluck('uri'))->toContain($uri);
    });
});

describe('CalendarSharing', function () {
    it('invites and accepts a share', function () {
        $owner = makeUser();
        $recipient = makeUser();
        $calendar = app(DavService::class)->calendars()->list($owner)[0];

        $sharing = app(DavService::class)->calendars()->sharing();
        $sharing->inviteUser($calendar, $owner, $recipient);

        $invites = $sharing->listInvites($recipient);
        expect($invites)->toHaveCount(1);

        $sharing->acceptInvite($invites->first()->shareToken);

        $accepted = $sharing->listAcceptedShares($recipient);
        expect($accepted)->toHaveCount(1);
    });
});

describe('per-user DAV deletion', function () {
    it('keeps the owner calendar and objects when deleting the sharee', function () {
        $owner = makeUser();
        $sharee = makeUser();
        $dav = app(DavService::class);
        $dav->principals()->create($owner->email);
        $dav->principals()->create($sharee->email);

        $calendar = $dav->calendars()->list($owner)[0];
        $dav->calendars()->events()->create($calendar, EventDTO::fromRequest([
            'title' => 'Owner event',
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
        ], $calendar, $owner));

        $dav->calendars()->sharing()->inviteUser($calendar, $owner, $sharee);
        $invite = $dav->calendars()->sharing()->listInvites($sharee)->first();
        $dav->calendars()->sharing()->acceptInvite($invite->shareToken);

        $summary = $dav->deleteForUser($sharee);

        expect($summary['shared_instances'])->toBe(1)
            ->and($summary['owned_calendars'])->toBe(1)
            ->and($summary['principal'])->toBeTrue();

        $pgsql = DB::connection('pgsql');
        expect($pgsql->table('calendarinstances')->where('calendarid', $calendar->calendarId)->count())->toBe(1)
            ->and($pgsql->table('calendarobjects')->where('calendarid', $calendar->calendarId)->count())->toBe(1)
            ->and($pgsql->table('calendars')->where('id', $calendar->calendarId)->count())->toBe(1)
            ->and($pgsql->table('calendarinstances')->where('principaluri', 'principals/'.$sharee->email)->count())->toBe(0)
            ->and($pgsql->table('principals')->where('uri', 'like', 'principals/'.$sharee->email.'%')->count())->toBe(0)
            ->and($pgsql->table('principals')->where('uri', 'principals/'.$owner->email)->count())->toBe(1);

        expect($dav->calendars()->list($owner))->toHaveCount(1);
    });

    it('removes the sharee instance too when deleting the owner', function () {
        $owner = makeUser();
        $sharee = makeUser();
        $dav = app(DavService::class);

        $calendar = $dav->calendars()->list($owner)[0];
        $dav->calendars()->sharing()->inviteUser($calendar, $owner, $sharee);
        $invite = $dav->calendars()->sharing()->listInvites($sharee)->first();
        $dav->calendars()->sharing()->acceptInvite($invite->shareToken);

        $summary = $dav->deleteForUser($owner);

        expect($summary['owned_calendars'])->toBe(1);

        $pgsql = DB::connection('pgsql');
        expect($pgsql->table('calendarinstances')->where('calendarid', $calendar->calendarId)->count())->toBe(0)
            ->and($pgsql->table('calendarobjects')->where('calendarid', $calendar->calendarId)->count())->toBe(0)
            ->and($pgsql->table('calendars')->where('id', $calendar->calendarId)->count())->toBe(0);

        expect($dav->calendars()->list($sharee))->toHaveCount(1);
    });
});

describe('VCardPhotoProcessor', function () {
    it('leaves vcards without a photo unchanged', function () {
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:John Doe\r\nEND:VCARD\r\n";

        expect(app(VCardPhotoProcessor::class)->process($vcard))->toBe($vcard);
    });

    it('removes a base64 photo block', function () {
        $base64 = base64_encode('fake-image-bytes');
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:John Doe\r\nPHOTO;ENCODING=b;TYPE=JPEG:{$base64}\r\nEND:VCARD\r\n";

        $result = app(VCardPhotoProcessor::class)->process($vcard);

        expect($result)->toContain('FN:John Doe');
        expect($result)->toContain('END:VCARD');
        expect($result)->toContain('PHOTO;ENCODING=b;TYPE=JPEG:');
    });
});

describe('DavServerFactory', function () {
    it('creates a sabre server', function () {
        $server = app(DavServerFactory::class)->createServer();

        expect($server)->toBeInstanceOf(\Sabre\DAV\Server::class);
    });
});
