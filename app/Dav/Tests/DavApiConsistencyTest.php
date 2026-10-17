<?php

use App\Api\Calendars\Jobs\RefreshCalendarCache;
use App\Api\Contacts\Jobs\RefreshContactsCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2030-10-10 12:00:00');
    $this->user = davUser();
    $this->user->settings()->update(['timezone' => 'UTC']);
    $this->calendar = 'calendars/'.$this->user->email.'/my-calendar/';
    $this->addressBook = 'addressbooks/'.$this->user->email.'/contacts/';
});

afterEach(function () {
    Carbon::setTestNow();
});

function apiEvents(\Tests\TestCase $test, \App\Api\Users\Models\User $user): \Illuminate\Support\Collection
{
    return collect($test->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/events/2030-10')->assertStatus(200)->json('data'));
}

function apiContacts(\Tests\TestCase $test, \App\Api\Users\Models\User $user): \Illuminate\Support\Collection
{
    return collect($test->actingAs($user, 'sanctum')->getJson('/api/v1/address-books/contacts')->assertStatus(200)->json('data'));
}

describe('Events: DAV and API agree', function () {
    it('shows an event written over DAV in the API, unescaped', function () {
        davRequest($this->user, 'PUT', $this->calendar.'dav.ics', davIcs('dav', 'Lunch\, then walk', '20301015T120000Z', '20301015T130000Z', [
            'LOCATION:Alexanderplatz 1\, Berlin',
            'DESCRIPTION:Line one\nLine two',
        ]), ['Content-Type' => 'text/calendar']);

        $event = apiEvents($this, $this->user)->firstWhere('uri', 'dav.ics');

        expect($event)->not->toBeNull()
            ->and($event['title'])->toBe('Lunch, then walk')
            ->and($event['location'])->toBe('Alexanderplatz 1, Berlin')
            ->and($event['description'])->toBe("Line one\nLine two")
            ->and($event['start_date'])->toBe('2030-10-15T12:00:00');
    });

    it('returns a title with a comma unchanged after an API round trip', function () {
        $instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars/'.$instanceId.'/events', [
            'title' => 'Lunch, then walk',
            'start_date' => '2030-10-15 12:00:00',
            'end_date' => '2030-10-15 13:00:00',
            'is_all_day' => false,
            'calendar_id' => $instanceId,
        ])->assertStatus(201);

        expect(apiEvents($this, $this->user)->first()['title'])->toBe('Lunch, then walk');
    });

    it('serves an event created through the API over DAV', function () {
        $instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');

        $uri = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars/'.$instanceId.'/events', [
            'title' => 'Team, weekly; sync',
            'location' => 'Room 4, 2nd floor',
            'start_date' => '2030-10-15 09:00:00',
            'end_date' => '2030-10-15 10:00:00',
            'is_all_day' => false,
            'calendar_id' => $instanceId,
        ])->assertStatus(201)->json('data.uri');

        $get = davRequest($this->user, 'GET', $this->calendar.$uri);
        $vevent = \Sabre\VObject\Reader::read($get->getBodyAsString())->VEVENT;

        expect($get->getStatus())->toBe(200)
            ->and((string) $vevent->SUMMARY)->toBe('Team, weekly; sync')
            ->and((string) $vevent->LOCATION)->toBe('Room 4, 2nd floor');
    });

    it('serves an API update and an API delete over DAV', function () {
        $etag = davRequest($this->user, 'PUT', $this->calendar.'dav.ics', davIcs('dav', 'Before', '20301015T120000Z', '20301015T130000Z'), ['Content-Type' => 'text/calendar'])->getHeader('ETag');
        $instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$instanceId.'/events/dav.ics', [
            'title' => 'After',
            'start_date' => '2030-10-15 12:00:00',
            'end_date' => '2030-10-15 13:00:00',
            'is_all_day' => false,
            'calendar_id' => $instanceId,
            'etag' => $etag,
        ])->assertStatus(200);
        $afterUpdate = davRequest($this->user, 'GET', $this->calendar.'dav.ics')->getBodyAsString();

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/calendars/'.$instanceId.'/events/dav.ics')->assertStatus(200);

        expect($afterUpdate)->toContain('SUMMARY:After')
            ->and(davRequest($this->user, 'GET', $this->calendar.'dav.ics')->getStatus())->toBe(404);
    });

    it('keeps the API event listing working when a client stores a VTODO', function () {
        davRequest($this->user, 'PUT', $this->calendar.'event.ics', davIcs('event', 'Event', '20301015T120000Z', '20301015T130000Z'), ['Content-Type' => 'text/calendar']);
        davRequest($this->user, 'PUT', $this->calendar.'todo.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Solyto//Tests//EN\r\nBEGIN:VTODO\r\nUID:todo\r\nDTSTAMP:20300101T000000Z\r\nDUE:20301015T120000Z\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n", ['Content-Type' => 'text/calendar']);

        expect(apiEvents($this, $this->user)->pluck('uri')->all())->toBe(['event.ics']);
    });
});

describe('Calendars: DAV and API agree', function () {
    it('lists a calendar created over DAV after the API listing was cached', function () {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->assertJsonCount(1, 'data');

        davRequest($this->user, 'MKCALENDAR', 'calendars/'.$this->user->email.'/work/', '<?xml version="1.0"?>'
            .'<cal:mkcalendar xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>Work</d:displayname></d:prop></d:set></cal:mkcalendar>');

        $names = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->pluck('name');

        expect($names->all())->toContain('Work');
    });

    it('drops a calendar deleted over DAV from the API listing', function () {
        davRequest($this->user, 'MKCALENDAR', 'calendars/'.$this->user->email.'/work/', '<?xml version="1.0"?>'
            .'<cal:mkcalendar xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>Work</d:displayname></d:prop></d:set></cal:mkcalendar>');
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->assertJsonCount(2, 'data');

        davRequest($this->user, 'DELETE', 'calendars/'.$this->user->email.'/work/');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->assertJsonCount(1, 'data');
    });

    it('shows a color changed over DAV in the API', function () {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars');

        davRequest($this->user, 'PROPPATCH', $this->calendar, '<?xml version="1.0"?>'
            .'<d:propertyupdate xmlns:d="DAV:" xmlns:apple="http://apple.com/ns/ical/"><d:set><d:prop>'
            .'<apple:calendar-color>#123456FF</apple:calendar-color></d:prop></d:set></d:propertyupdate>');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->assertJsonPath('data.0.color', '#123456FF');
    });
});

describe('Contacts: DAV and API agree', function () {
    it('shows a card written over DAV in the API, unescaped', function () {
        davRequest($this->user, 'PUT', $this->addressBook.'dav.vcf', davVcard('dav', 'Doe\, Jane', [
            'ORG:Acme\, Inc.',
            'NOTE:First line\nSecond line',
        ]), ['Content-Type' => 'text/vcard']);

        $contact = apiContacts($this, $this->user)->firstWhere('uri', 'dav.vcf');

        expect($contact)->not->toBeNull()
            ->and($contact['full_name'])->toBe('Doe, Jane')
            ->and($contact['organization'])->toBe('Acme, Inc.')
            ->and($contact['note'])->toBe("First line\nSecond line");
    });

    it('refreshes a cached API contact listing after a DAV write and a DAV delete', function () {
        expect(apiContacts($this, $this->user))->toBeEmpty();

        davRequest($this->user, 'PUT', $this->addressBook.'dav.vcf', davVcard('dav'), ['Content-Type' => 'text/vcard']);
        $afterPut = apiContacts($this, $this->user)->pluck('uri')->all();

        davRequest($this->user, 'DELETE', $this->addressBook.'dav.vcf');
        $afterDelete = apiContacts($this, $this->user)->pluck('uri')->all();

        expect($afterPut)->toBe(['dav.vcf'])
            ->and($afterDelete)->toBe([]);
    });

    it('serves a contact created through the API over DAV', function () {
        $addressBookId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/address-books')->json('data.0.id');

        $uri = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/address-books/'.$addressBookId.'/contacts', [
            'address_book_id' => $addressBookId,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'organization' => 'Acme, Inc.',
        ])->assertStatus(201)->json('data.uri');

        $vcard = \Sabre\VObject\Reader::read(davRequest($this->user, 'GET', $this->addressBook.$uri)->getBodyAsString());

        expect((string) $vcard->FN)->toBe('Jane Doe')
            ->and((string) $vcard->ORG)->toBe('Acme, Inc.');
    });
});

describe('CacheInvalidationPlugin', function () {
    beforeEach(function () {
        $this->cache = Mockery::spy(\App\Shared\Services\UserCacheService::class);
        $this->app->instance(\App\Shared\Services\UserCacheService::class, $this->cache);
        $this->calendarId = app(\App\Dav\Services\DavService::class)->calendars()->list($this->user)[0]->calendarId;
        $this->addressBookId = app(\App\Dav\Services\DavService::class)->addressBooks()->list($this->user)[0]->id;
        Queue::fake();
    });

    it('drops the cached events and queues a refresh on a DAV event write and delete', function (string $method) {
        davRequest($this->user, 'PUT', $this->calendar.'a.ics', davIcs('a'), ['Content-Type' => 'text/calendar']);
        if ($method === 'DELETE') {
            davRequest($this->user, 'DELETE', $this->calendar.'a.ics');
        }

        $this->cache->shouldHaveReceived('forgetByPrefix')->with(['calendar_events', $this->user->id, $this->calendarId])->atLeast()->once();
        Queue::assertPushed(RefreshCalendarCache::class, fn (RefreshCalendarCache $job) => $job->userId === $this->user->id);
    })->with(['PUT', 'DELETE']);

    it('drops the cached calendar list when a calendar is created or deleted over DAV', function () {
        davRequest($this->user, 'MKCALENDAR', 'calendars/'.$this->user->email.'/work/');
        davRequest($this->user, 'DELETE', 'calendars/'.$this->user->email.'/work/');

        $this->cache->shouldHaveReceived('forget')->with(['calendars', $this->user->id])->twice();
    });

    it('drops the cached contacts and queues a refresh on a DAV card write', function () {
        davRequest($this->user, 'PUT', $this->addressBook.'a.vcf', davVcard('a'), ['Content-Type' => 'text/vcard']);

        $this->cache->shouldHaveReceived('forget')->with(['contacts', $this->user->id, $this->addressBookId])->once();
        Queue::assertPushed(RefreshContactsCache::class, fn (RefreshContactsCache $job) => $job->userId === $this->user->id);
    });

    it('drops the cached address book list when an address book is created over DAV', function () {
        davRequest($this->user, 'MKCOL', 'addressbooks/'.$this->user->email.'/work/', '<?xml version="1.0"?>'
            .'<d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop>'
            .'<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype></d:prop></d:set></d:mkcol>');

        $this->cache->shouldHaveReceived('forget')->with(['address_books', $this->user->id])->once();
    });

    it('touches no cache and queues nothing for a rejected write', function () {
        $other = davUser();

        davRequest($other, 'PUT', $this->calendar.'a.ics', davIcs('a'), ['Content-Type' => 'text/calendar']);

        $this->cache->shouldNotHaveReceived('forgetByPrefix');
        Queue::assertNothingPushed();
    });
});
