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

function apiEdit(\Tests\TestCase $test, string $uri, array $fields): \Sabre\VObject\Component\VCalendar
{
    $path = 'calendars/'.$test->user->email.'/my-calendar/'.$uri;
    $test->actingAs($test->user, 'sanctum')->putJson('/api/v1/calendars/'.$test->instanceId.'/events/'.$uri, array_merge([
        'is_all_day' => false,
        'calendar_id' => $test->instanceId,
        'etag' => davRequest($test->user, 'GET', $path)->getHeader('ETag'),
    ], $fields))->assertStatus(200);

    return \Sabre\VObject\Reader::read(davRequest($test->user, 'GET', $path)->getBodyAsString());
}

function seriesWithMovedDate(): string
{
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN',
        'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'DTSTART:20301014T090000Z', 'DTEND:20301014T091500Z',
        'RRULE:FREQ=DAILY;COUNT=4', 'EXDATE:20301015T090000Z', 'SUMMARY:Standup', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID:20301016T090000Z',
        'DTSTART:20301016T110000Z', 'DTEND:20301016T111500Z', 'SUMMARY:Standup (moved)', 'END:VEVENT',
        'END:VCALENDAR',
    ])."\r\n";
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

    it('keeps the UID, deleted occurrences and reminders when an event is edited in the app', function () {
        $path = $this->calendar.'series.ics';
        $etag = davRequest($this->user, 'PUT', $path, davIcs('original-uid', 'Standup', '20301014T090000Z', '20301014T091500Z', [
            'RRULE:FREQ=DAILY;COUNT=4', 'EXDATE:20301015T090000Z',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT10M', 'END:VALARM',
        ]), ['Content-Type' => 'text/calendar'])->getHeader('ETag');
        $instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$instanceId.'/events/series.ics', [
            'title' => 'Standup (renamed)',
            'start_date' => '2030-10-14 09:00:00',
            'end_date' => '2030-10-14 09:15:00',
            'is_all_day' => false,
            'is_recurring' => true,
            'recurrence_rule' => 'FREQ=DAILY;COUNT=4',
            'calendar_id' => $instanceId,
            'etag' => $etag,
        ])->assertStatus(200);
        $vevent = \Sabre\VObject\Reader::read(davRequest($this->user, 'GET', $path)->getBodyAsString())->VEVENT;

        expect((string) $vevent->SUMMARY)->toBe('Standup (renamed)')
            ->and((string) $vevent->UID)->toBe('original-uid')
            ->and(isset($vevent->EXDATE))->toBeTrue()
            ->and(isset($vevent->VALARM))->toBeTrue();
    });

    it('keeps the API event listing working when a client stores a VTODO', function () {
        davRequest($this->user, 'PUT', $this->calendar.'event.ics', davIcs('event', 'Event', '20301015T120000Z', '20301015T130000Z'), ['Content-Type' => 'text/calendar']);
        davRequest($this->user, 'PUT', $this->calendar.'todo.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Solyto//Tests//EN\r\nBEGIN:VTODO\r\nUID:todo\r\nDTSTAMP:20300101T000000Z\r\nDUE:20301015T120000Z\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n", ['Content-Type' => 'text/calendar']);

        expect(apiEvents($this, $this->user)->pluck('uri')->all())->toBe(['event.ics']);
    });
});

describe('Events: editing through the API keeps client data', function () {
    beforeEach(function () {
        $this->instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
    });

    it('keeps a moved occurrence and a deleted date when the series is renamed', function () {
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);

        apiEdit($this, 'series.ics', ['title' => 'Daily sync', 'start_date' => '2030-10-14 09:00:00', 'end_date' => '2030-10-14 09:15:00', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=4']);

        expect(apiEvents($this, $this->user)->map(fn (array $e) => $e['title'].' '.$e['start_date'])->all())->toBe([
            'Daily sync 2030-10-14T09:00:00',
            'Standup (moved) 2030-10-16T11:00:00',
            'Daily sync 2030-10-17T09:00:00',
        ]);
    });

    it('drops deleted and moved dates when the series start moves', function () {
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);

        $vcalendar = apiEdit($this, 'series.ics', ['title' => 'Standup', 'start_date' => '2030-10-14 10:00:00', 'end_date' => '2030-10-14 10:15:00', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=4']);

        expect(count($vcalendar->VEVENT))->toBe(1)
            ->and(isset($vcalendar->VEVENT->EXDATE))->toBeFalse()
            ->and((string) $vcalendar->VEVENT->UID)->toBe('series');
    });

    it('drops deleted and moved dates when the series turns all-day', function () {
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);

        $vcalendar = apiEdit($this, 'series.ics', ['title' => 'Standup', 'start_date' => '2030-10-14 00:00:00', 'end_date' => '2030-10-14 00:00:00', 'is_all_day' => true, 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=4']);

        expect(count($vcalendar->VEVENT))->toBe(1)
            ->and(isset($vcalendar->VEVENT->EXDATE))->toBeFalse()
            ->and($vcalendar->VEVENT->DTSTART->getValueType())->toBe('DATE');
    });

    it('keeps attendees, the organizer and client extras of a single event and counts the change', function () {
        davRequest($this->user, 'PUT', $this->calendar.'meeting.ics', davIcs('meeting', 'Meeting', '20301015T120000Z', '20301015T130000Z', [
            'ORGANIZER:mailto:boss@example.org', 'ATTENDEE;CN=Jane:mailto:jane@example.org', 'X-APPLE-TRAVEL-ADVISORY-BEHAVIOR:AUTOMATIC', 'SEQUENCE:3',
        ]), ['Content-Type' => 'text/calendar']);

        $vevent = apiEdit($this, 'meeting.ics', ['title' => 'Meeting (room 2)', 'location' => 'Room 2', 'start_date' => '2030-10-15 12:00:00', 'end_date' => '2030-10-15 13:30:00'])->VEVENT;

        expect((string) $vevent->SUMMARY)->toBe('Meeting (room 2)')
            ->and((string) $vevent->LOCATION)->toBe('Room 2')
            ->and($vevent->DTEND->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-15 13:30')
            ->and((string) $vevent->UID)->toBe('meeting')
            ->and((string) $vevent->ORGANIZER)->toBe('mailto:boss@example.org')
            ->and((string) $vevent->ATTENDEE)->toBe('mailto:jane@example.org')
            ->and((string) $vevent->{'X-APPLE-TRAVEL-ADVISORY-BEHAVIOR'})->toBe('AUTOMATIC')
            ->and((string) $vevent->SEQUENCE)->toBe('4');
    });

    it('keeps an Outlook event readable after an edit in another time zone', function () {
        davRequest($this->user, 'PUT', $this->calendar.'outlook.ics', file_get_contents(base_path('tests/Fixtures/dav/outlook-windows-timezone.ics')), ['Content-Type' => 'text/calendar']);
        $this->user->settings()->update(['timezone' => 'Europe/Berlin']);

        $vcalendar = apiEdit($this, 'outlook.ics', ['title' => 'Quartalsplanung Q4 (verschoben)', 'start_date' => '2030-10-16 15:00:00', 'end_date' => '2030-10-16 16:00:00']);
        $timezones = collect($vcalendar->select('VTIMEZONE'))->map(fn ($tz) => (string) $tz->TZID)->all();

        expect($timezones)->toBe(['W. Europe Standard Time', 'Europe/Berlin'])
            ->and($vcalendar->VEVENT->DTSTART->getDateTime()->format('Y-m-d H:i P'))->toBe('2030-10-16 15:00 +02:00')
            ->and($vcalendar->validate())->toBe([]);
    });

    it('keeps the UID and the reminder when an event is moved to another calendar', function () {
        davRequest($this->user, 'MKCALENDAR', 'calendars/'.$this->user->email.'/work/', '<?xml version="1.0"?>'
            .'<cal:mkcalendar xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>Work</d:displayname></d:prop></d:set></cal:mkcalendar>');
        davRequest($this->user, 'PUT', $this->calendar.'a.ics', davIcs('keep-me', 'Event', '20301015T120000Z', '20301015T130000Z', [
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT10M', 'END:VALARM',
        ]), ['Content-Type' => 'text/calendar']);
        $workId = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Work')['id'];

        $moved = $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->instanceId.'/events/a.ics', [
            'title' => 'Event', 'start_date' => '2030-10-15 12:00:00', 'end_date' => '2030-10-15 13:00:00', 'is_all_day' => false,
            'calendar_id' => $workId, 'etag' => davRequest($this->user, 'GET', $this->calendar.'a.ics')->getHeader('ETag'),
        ])->assertStatus(200)->json('data.uri');
        $vevent = \Sabre\VObject\Reader::read(davRequest($this->user, 'GET', 'calendars/'.$this->user->email.'/work/'.$moved)->getBodyAsString())->VEVENT;

        expect((string) $vevent->UID)->toBe('keep-me')
            ->and(isset($vevent->VALARM))->toBeTrue()
            ->and(davRequest($this->user, 'GET', $this->calendar.'a.ics')->getStatus())->toBe(404);
    });
});

describe('Events: editing a series through one of its occurrences', function () {
    beforeEach(function () {
        $this->instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);
    });

    function seriesEdit(string $start, string $end, string $occurrence, string $title = 'Standup'): array
    {
        return ['title' => $title, 'start_date' => $start, 'end_date' => $end, 'occurrence_start_date' => $occurrence, 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=4'];
    }

    it('keeps the series start and its exceptions when renamed through a later occurrence', function () {
        apiEdit($this, 'series.ics', seriesEdit('2030-10-17 09:00:00', '2030-10-17 09:15:00', '2030-10-17 09:00:00', 'Daily sync'));

        expect(apiEvents($this, $this->user)->map(fn (array $e) => $e['title'].' '.$e['start_date'])->all())->toBe([
            'Daily sync 2030-10-14T09:00:00',
            'Standup (moved) 2030-10-16T11:00:00',
            'Daily sync 2030-10-17T09:00:00',
        ]);
    });

    it('keeps the series and the moved occurrence when renamed through the moved occurrence', function () {
        $vcalendar = apiEdit($this, 'series.ics', seriesEdit('2030-10-16 11:00:00', '2030-10-16 11:15:00', '2030-10-16 11:00:00', 'Daily sync'));
        $master = collect($vcalendar->VEVENT)->first(fn ($vevent) => ! isset($vevent->{'RECURRENCE-ID'}));

        expect($master->DTSTART->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-14 09:00')
            ->and(count($vcalendar->VEVENT))->toBe(2)
            ->and(isset($master->EXDATE))->toBeTrue();
    });

    it('moves the whole series by the hour an occurrence was moved', function () {
        $vevent = apiEdit($this, 'series.ics', seriesEdit('2030-10-17 10:00:00', '2030-10-17 10:15:00', '2030-10-17 09:00:00'))->VEVENT;

        expect($vevent->DTSTART->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-14 10:00')
            ->and($vevent->DTEND->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-14 10:15');
    });

    it('accepts the dates in the format the app sends them', function () {
        $vevent = apiEdit($this, 'series.ics', seriesEdit('2030-10-16T10:00:00', '2030-10-16T10:15:00', '2030-10-16T09:00:00'))->VEVENT;

        expect($vevent->DTSTART->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-14 10:00');
    });

    it('moves the whole series by the day an occurrence was dragged', function () {
        $vevent = apiEdit($this, 'series.ics', seriesEdit('2030-10-18 09:00:00', '2030-10-18 09:15:00', '2030-10-17 09:00:00'))->VEVENT;

        expect($vevent->DTSTART->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-15 09:00');
    });

    it('keeps the local time when a series moves by a day across a daylight saving change', function () {
        $this->user->settings()->update(['timezone' => 'America/New_York']);
        davRequest($this->user, 'PUT', $this->calendar.'ny.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:ny', 'DTSTAMP:20300101T000000Z',
            'DTSTART;TZID=America/New_York:20301031T090000', 'DTEND;TZID=America/New_York:20301031T093000',
            'RRULE:FREQ=DAILY;COUNT=10', 'SUMMARY:Morning', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);

        $vevent = apiEdit($this, 'ny.ics', [
            'title' => 'Morning', 'start_date' => '2030-11-03 09:00:00', 'end_date' => '2030-11-03 09:30:00',
            'occurrence_start_date' => '2030-11-02 09:00:00', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=10',
        ])->VEVENT;

        expect($vevent->DTSTART->getDateTime()->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i'))->toBe('2030-11-01 09:00');
    });

    it('moves an all-day series by the day an occurrence was dragged', function () {
        $this->user->settings()->update(['timezone' => 'America/New_York']);
        davRequest($this->user, 'PUT', $this->calendar.'bins.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:bins', 'DTSTAMP:20300101T000000Z',
            'DTSTART;VALUE=DATE:20301007', 'DTEND;VALUE=DATE:20301008', 'RRULE:FREQ=WEEKLY;COUNT=3', 'SUMMARY:Bin day', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);

        $vevent = apiEdit($this, 'bins.ics', [
            'title' => 'Bin day', 'start_date' => '2030-10-15 00:00:00', 'end_date' => '2030-10-15 00:00:00', 'is_all_day' => true,
            'occurrence_start_date' => '2030-10-14 00:00:00', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=WEEKLY;COUNT=3',
        ])->VEVENT;

        expect((string) $vevent->DTSTART)->toBe('20301008')
            ->and((string) $vevent->DTEND)->toBe('20301009');
    });

    // Frontend builds from before occurrence_start_date do not send it.
    it('starts the series at start_date when occurrence_start_date is missing', function () {
        $vevent = apiEdit($this, 'series.ics', [
            'title' => 'Standup', 'start_date' => '2030-10-16 09:00:00', 'end_date' => '2030-10-16 09:15:00', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=4',
        ])->VEVENT;

        expect($vevent->DTSTART->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-16 09:00');
    });
});

describe('Events: switching repetition off through the API', function () {
    beforeEach(function () {
        $this->instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);
    });

    it('turns the series into a single event on its first date when repeat is switched off via an occurrence', function () {
        $vcalendar = apiEdit($this, 'series.ics', [
            'title' => 'Standup', 'start_date' => '2030-10-16T11:00:00', 'end_date' => '2030-10-16T11:15:00',
            'occurrence_start_date' => '2030-10-16T11:00:00', 'is_recurring' => false, 'recurrence_rule' => null, 'recurrence_end' => '2030-10-17T09:00:00',
        ]);

        expect(count($vcalendar->VEVENT))->toBe(1)
            ->and(isset($vcalendar->VEVENT->RRULE))->toBeFalse()
            ->and(isset($vcalendar->VEVENT->EXDATE))->toBeFalse()
            ->and(apiEvents($this, $this->user)->map(fn (array $e) => $e['start_date'].' '.($e['is_recurring'] ? 'recurring' : 'single').' '.($e['recurrence_end'] ?? '-'))->all())
            ->toBe(['2030-10-14T09:00:00 single -']);
    });

    it('stops repeating at start_date when an older frontend sends no occurrence_start_date', function () {
        $vevent = apiEdit($this, 'series.ics', [
            'title' => 'Standup', 'start_date' => '2030-10-14T09:00:00', 'end_date' => '2030-10-14T09:15:00', 'is_recurring' => false, 'recurrence_rule' => null,
        ])->VEVENT;

        expect(isset($vevent->RRULE))->toBeFalse()
            ->and($vevent->DTSTART->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-14 09:00');
    });

    it('keeps the series repeating when a drag sends the stored rule back', function () {
        $vcalendar = apiEdit($this, 'series.ics', [
            'title' => 'Standup', 'start_date' => '2030-10-17T10:00:00', 'end_date' => '2030-10-17T10:15:00',
            'occurrence_start_date' => '2030-10-17T09:00:00', 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY;COUNT=4',
        ]);

        expect((string) $vcalendar->VEVENT->RRULE)->toBe('FREQ=DAILY;COUNT=4');
    });

    it('keeps the rule when is_recurring is not sent at all', function () {
        $vcalendar = apiEdit($this, 'series.ics', ['title' => 'Daily sync', 'start_date' => '2030-10-14T09:00:00', 'end_date' => '2030-10-14T09:15:00']);

        expect((string) $vcalendar->VEVENT->RRULE)->toBe('FREQ=DAILY;COUNT=4');
    });
});

describe('Events: changing only one occurrence through the API', function () {
    beforeEach(function () {
        $this->instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
    });

    function occurrenceRequest(\Tests\TestCase $test, string $method, string $uri, string $isoDate, array $fields = []): \Sabre\VObject\Component\VCalendar
    {
        $url = '/api/v1/calendars/'.$test->instanceId.'/events/'.$uri.'/occurrence/'.rawurlencode($isoDate);
        $response = $method === 'DELETE'
            ? $test->actingAs($test->user, 'sanctum')->deleteJson($url)
            : $test->actingAs($test->user, 'sanctum')->putJson($url, array_merge(['is_all_day' => false, 'calendar_id' => $test->instanceId, 'etag' => 'unused'], $fields));
        $response->assertStatus(200);

        return \Sabre\VObject\Reader::read(davRequest($test->user, 'GET', 'calendars/'.$test->user->email.'/my-calendar/'.$uri)->getBodyAsString());
    }

    function exceptionsOf(\Sabre\VObject\Component\VCalendar $vcalendar): array
    {
        return collect($vcalendar->select('VEVENT'))->filter(fn ($vevent) => isset($vevent->{'RECURRENCE-ID'}))->values()->all();
    }

    it('lists the original slot of a moved occurrence', function () {
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);

        expect(apiEvents($this, $this->user)->map(fn (array $e) => $e['start_date'].' / '.$e['original_start_date'])->all())->toBe([
            '2030-10-14T09:00:00 / 2030-10-14T09:00:00',
            '2030-10-16T11:00:00 / 2030-10-16T09:00:00',
            '2030-10-17T09:00:00 / 2030-10-17T09:00:00',
        ]);
    });

    it('edits a moved occurrence in place, addressed by its original slot or by its moved time', function (string $isoDate) {
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);

        $exceptions = exceptionsOf(occurrenceRequest($this, 'PUT', 'series.ics', $isoDate, [
            'title' => 'Renamed', 'start_date' => '2030-10-16 11:30:00', 'end_date' => '2030-10-16 11:45:00',
        ]));

        expect($exceptions)->toHaveCount(1)
            ->and($exceptions[0]->{'RECURRENCE-ID'}->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-16 09:00')
            ->and($exceptions[0]->DTSTART->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-16 11:30')
            ->and((string) $exceptions[0]->SUMMARY)->toBe('Renamed');
    })->with(['original slot' => '2030-10-16T09:00:00.000Z', 'moved time' => '2030-10-16T11:00:00.000Z']);

    it('deletes a moved occurrence completely, addressed by its original slot or by its moved time', function (string $isoDate) {
        davRequest($this->user, 'PUT', $this->calendar.'series.ics', seriesWithMovedDate(), ['Content-Type' => 'text/calendar']);

        $vcalendar = occurrenceRequest($this, 'DELETE', 'series.ics', $isoDate);
        $exdates = collect($vcalendar->VEVENT->select('EXDATE'))->map(fn ($exdate) => $exdate->getDateTime()->format('Y-m-d H:i'))->all();

        expect(exceptionsOf($vcalendar))->toBe([])
            ->and($exdates)->toBe(['2030-10-15 09:00', '2030-10-16 09:00'])
            ->and(apiEvents($this, $this->user)->pluck('start_date')->all())->toBe(['2030-10-14T09:00:00', '2030-10-17T09:00:00']);
    })->with(['original slot' => '2030-10-16T09:00:00.000Z', 'moved time' => '2030-10-16T11:00:00.000Z']);

    it('carries the reminder of the series into a new exception', function () {
        davRequest($this->user, 'PUT', $this->calendar.'daily.ics', davIcs('daily', 'Standup', '20301014T090000Z', '20301014T091500Z', [
            'RRULE:FREQ=DAILY;COUNT=4', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT10M', 'END:VALARM',
        ]), ['Content-Type' => 'text/calendar']);

        $exceptions = exceptionsOf(occurrenceRequest($this, 'PUT', 'daily.ics', '2030-10-17T09:00:00.000Z', [
            'title' => 'Standup (late)', 'start_date' => '2030-10-17 10:00:00', 'end_date' => '2030-10-17 10:15:00',
        ]));

        expect($exceptions)->toHaveCount(1)
            ->and(isset($exceptions[0]->VALARM))->toBeTrue()
            ->and(isset($exceptions[0]->RRULE))->toBeFalse();
    });

    it('stores a moved time correctly when the user is in another zone than the series', function () {
        $this->user->settings()->update(['timezone' => 'Europe/Berlin']);
        davRequest($this->user, 'PUT', $this->calendar.'utc.ics', davIcs('utc', 'Standup', '20301014T070000Z', '20301014T071500Z', [
            'RRULE:FREQ=DAILY;COUNT=4',
        ]), ['Content-Type' => 'text/calendar']);

        $exceptions = exceptionsOf(occurrenceRequest($this, 'PUT', 'utc.ics', '2030-10-16T07:00:00.000Z', [
            'title' => 'Standup', 'start_date' => '2030-10-16 11:00:00', 'end_date' => '2030-10-16 11:15:00',
        ]));

        expect($exceptions[0]->DTSTART->getDateTime()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'))->toBe('2030-10-16 11:00');
    });

    it('deletes the right day of an all-day series in every time zone', function (string $timezone, string $isoDate) {
        $this->user->settings()->update(['timezone' => $timezone]);
        davRequest($this->user, 'PUT', $this->calendar.'bins.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:bins', 'DTSTAMP:20300101T000000Z',
            'DTSTART;VALUE=DATE:20301007', 'DTEND;VALUE=DATE:20301008', 'RRULE:FREQ=WEEKLY;COUNT=3', 'SUMMARY:Bin day', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);

        $vcalendar = occurrenceRequest($this, 'DELETE', 'bins.ics', $isoDate);

        expect((string) $vcalendar->VEVENT->EXDATE)->toBe('20301014');
    })->with([
        'east of UTC' => ['Europe/Berlin', '2030-10-13T22:00:00.000Z'],
        'UTC' => ['UTC', '2030-10-14T00:00:00.000Z'],
        'west of UTC' => ['America/New_York', '2030-10-14T04:00:00.000Z'],
    ]);
});

describe('Events: times in the user\'s time zone', function () {
    beforeEach(function () {
        $this->user->settings()->update(['timezone' => 'Europe/Berlin']);
    });

    it('shows an event a client stored in UTC in the user\'s local time', function () {
        davRequest($this->user, 'PUT', $this->calendar.'utc.ics', davIcs('utc', 'Standup', '20301015T070000Z', '20301015T071500Z'), ['Content-Type' => 'text/calendar']);

        $event = apiEvents($this, $this->user)->first();

        expect($event['start_date'])->toBe('2030-10-15T09:00:00')
            ->and($event['end_date'])->toBe('2030-10-15T09:15:00');
    });

    it('shows a series stored in another zone in the user\'s local time, original slots included', function () {
        davRequest($this->user, 'PUT', $this->calendar.'ny.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:ny', 'DTSTAMP:20300101T000000Z',
            'DTSTART;TZID=America/New_York:20301014T090000', 'DTEND;TZID=America/New_York:20301014T093000',
            'RRULE:FREQ=DAILY;COUNT=2', 'SUMMARY:Call New York', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);

        expect(apiEvents($this, $this->user)->map(fn (array $e) => $e['start_date'].' / '.$e['original_start_date'])->all())->toBe([
            '2030-10-14T15:00:00 / 2030-10-14T15:00:00',
            '2030-10-15T15:00:00 / 2030-10-15T15:00:00',
        ]);
    });

    it('leaves all-day dates as they are', function () {
        davRequest($this->user, 'PUT', $this->calendar.'day.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:day', 'DTSTAMP:20300101T000000Z',
            'DTSTART;VALUE=DATE:20301015', 'DTEND;VALUE=DATE:20301016', 'SUMMARY:Holiday', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);

        $event = apiEvents($this, $this->user)->first();

        expect($event['start_date'])->toBe('2030-10-15T00:00:00')
            ->and($event['end_date'])->toBe('2030-10-15T00:00:00');
    });

    it('keeps the time of a UTC event when it is saved again with the shown local time', function () {
        davRequest($this->user, 'PUT', $this->calendar.'utc.ics', davIcs('utc', 'Standup', '20301015T070000Z', '20301015T071500Z'), ['Content-Type' => 'text/calendar']);
        $this->instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
        $shown = apiEvents($this, $this->user)->first();

        $vevent = apiEdit($this, 'utc.ics', ['title' => 'Standup (renamed)', 'start_date' => $shown['start_date'], 'end_date' => $shown['end_date']])->VEVENT;

        expect($vevent->DTSTART->getDateTime()->getTimestamp())->toBe((new \DateTime('2030-10-15 07:00:00', new \DateTimeZone('UTC')))->getTimestamp());
    });

    it('shows the times of a created event in the user\'s local time', function () {
        $instanceId = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');

        $created = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars/'.$instanceId.'/events', [
            'title' => 'Dentist', 'start_date' => '2030-10-15 09:00:00', 'end_date' => '2030-10-15 10:00:00', 'is_all_day' => false, 'calendar_id' => $instanceId,
        ])->assertStatus(201)->json('data');

        expect($created['start_date'])->toBe('2030-10-15T09:00:00')
            ->and(apiEvents($this, $this->user)->first()['start_date'])->toBe('2030-10-15T09:00:00');
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
