<?php

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function occurrenceCalendar(User $user): CalendarDTO
{
    return app(DavService::class)->calendars()->list($user)[0];
}

function occurrenceStarts(CalendarDTO $calendar, string $from = '2030-10-01', string $to = '2030-10-31'): array
{
    $events = app(DavService::class)->calendars()->events()->listExpanded(
        $calendar,
        new \DateTime($from.' 00:00:00', new \DateTimeZone('UTC')),
        new \DateTime($to.' 23:59:59', new \DateTimeZone('UTC')),
    );

    return collect($events)->map(fn (EventDTO $e) => $e->title.' '.$e->startDate->format('Y-m-d H:i'))->all();
}

function occurrenceUpdate(string $title, string $start, string $end): EventDTO
{
    return new EventDTO(
        id: null, title: $title, description: 'Moved by the app', startDate: new \DateTime($start, new \DateTimeZone('UTC')),
        endDate: new \DateTime($end, new \DateTimeZone('UTC')), isAllDay: false, location: 'Room 2', recurrenceRule: null,
        recurrenceEnd: null, timezone: 'UTC', uri: null, etag: null, calendarId: null, calendarName: null,
    );
}

beforeEach(function () {
    $this->user = davUser();
    $this->calendar = occurrenceCalendar($this->user);
    $this->path = 'calendars/'.$this->user->email.'/my-calendar/';
    $this->events = app(DavService::class)->calendars()->events();

    davRequest($this->user, 'PUT', $this->path.'daily.ics', davIcs('daily', 'Standup', '20301014T090000Z', '20301014T091500Z', [
        'RRULE:FREQ=DAILY;COUNT=4',
    ]), ['Content-Type' => 'text/calendar']);
});

describe('Deleting a single occurrence', function () {
    it('removes only that occurrence from the expanded series', function () {
        $deleted = $this->events->deleteOccurrence($this->calendar, 'daily.ics', new \DateTime('2030-10-15 09:00:00', new \DateTimeZone('UTC')));

        expect($deleted)->toBeTrue()
            ->and(occurrenceStarts($this->calendar))->toBe([
                'Standup 2030-10-14 09:00',
                'Standup 2030-10-16 09:00',
                'Standup 2030-10-17 09:00',
            ]);
    });

    it('writes an EXDATE that DAV clients see', function () {
        $this->events->deleteOccurrence($this->calendar, 'daily.ics', new \DateTime('2030-10-15 09:00:00', new \DateTimeZone('UTC')));

        $vevent = \Sabre\VObject\Reader::read(davRequest($this->user, 'GET', $this->path.'daily.ics')->getBodyAsString())->VEVENT;

        expect($vevent->EXDATE->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-15 09:00');
    });

    it('writes a DATE EXDATE for an all-day series', function () {
        davRequest($this->user, 'PUT', $this->path.'weekly.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:weekly', 'DTSTAMP:20300101T000000Z',
            'DTSTART;VALUE=DATE:20301007', 'DTEND;VALUE=DATE:20301008', 'RRULE:FREQ=WEEKLY;COUNT=3', 'SUMMARY:Bin day', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);

        $this->events->deleteOccurrence($this->calendar, 'weekly.ics', new \DateTime('2030-10-14 00:00:00', new \DateTimeZone('UTC')));

        expect(davRequest($this->user, 'GET', $this->path.'weekly.ics')->getBodyAsString())->toContain('EXDATE;VALUE=DATE:20301014');
    });

    it('refuses an event that does not recur', function () {
        davRequest($this->user, 'PUT', $this->path.'single.ics', davIcs('single'), ['Content-Type' => 'text/calendar']);

        expect($this->events->deleteOccurrence($this->calendar, 'single.ics', new \DateTime('2030-10-15 10:00:00')))->toBeFalse();
    });

    it('refuses an event that does not exist', function () {
        expect($this->events->deleteOccurrence($this->calendar, 'missing.ics', new \DateTime('2030-10-15 10:00:00')))->toBeFalse();
    });
});

describe('Updating a single occurrence', function () {
    it('moves only that occurrence and keeps the rest of the series', function () {
        $updated = $this->events->updateOccurrence(
            $this->calendar,
            'daily.ics',
            new \DateTime('2030-10-15 09:00:00', new \DateTimeZone('UTC')),
            occurrenceUpdate('Standup (late)', '2030-10-15 11:00:00', '2030-10-15 11:15:00'),
        );

        expect($updated)->toBeTrue()
            ->and(occurrenceStarts($this->calendar))->toBe([
                'Standup 2030-10-14 09:00',
                'Standup (late) 2030-10-15 11:00',
                'Standup 2030-10-16 09:00',
                'Standup 2030-10-17 09:00',
            ]);
    });

    it('stores the change as a RECURRENCE-ID exception that DAV clients see', function () {
        $this->events->updateOccurrence(
            $this->calendar,
            'daily.ics',
            new \DateTime('2030-10-15 09:00:00', new \DateTimeZone('UTC')),
            occurrenceUpdate('Standup (late)', '2030-10-15 11:00:00', '2030-10-15 11:15:00'),
        );

        $vcalendar = \Sabre\VObject\Reader::read(davRequest($this->user, 'GET', $this->path.'daily.ics')->getBodyAsString());
        $exception = collect($vcalendar->VEVENT)->first(fn ($vevent) => isset($vevent->{'RECURRENCE-ID'}));

        expect(count($vcalendar->VEVENT))->toBe(2)
            ->and($exception->{'RECURRENCE-ID'}->getDateTime()->format('Y-m-d H:i'))->toBe('2030-10-15 09:00')
            ->and((string) $exception->SUMMARY)->toBe('Standup (late)')
            ->and((string) $exception->LOCATION)->toBe('Room 2')
            ->and((string) $exception->UID)->toBe('daily');
    });

    it('refuses an event that does not recur', function () {
        davRequest($this->user, 'PUT', $this->path.'single.ics', davIcs('single'), ['Content-Type' => 'text/calendar']);

        expect($this->events->updateOccurrence(
            $this->calendar,
            'single.ics',
            new \DateTime('2030-10-15 10:00:00'),
            occurrenceUpdate('Moved', '2030-10-15 11:00:00', '2030-10-15 12:00:00'),
        ))->toBeFalse();
    });
});

describe('Expanding events for a window', function () {
    it('leaves out a single event that ends exactly at the window start', function () {
        davRequest($this->user, 'PUT', $this->path.'edge.ics', davIcs('edge', 'Edge', '20300930T230000Z', '20301001T000000Z'), ['Content-Type' => 'text/calendar']);

        expect(occurrenceStarts($this->calendar))->not->toContain('Edge 2030-09-30 23:00');
    });

    it('includes a single event that overlaps the window start', function () {
        davRequest($this->user, 'PUT', $this->path.'overlap.ics', davIcs('overlap', 'Overnight', '20300930T230000Z', '20301001T010000Z'), ['Content-Type' => 'text/calendar']);

        expect(occurrenceStarts($this->calendar))->toContain('Overnight 2030-09-30 23:00');
    });

    it('stops a series at its COUNT', function () {
        expect(occurrenceStarts($this->calendar, '2030-10-01', '2030-12-31'))->toHaveCount(4);
    });
});

// Byte for byte apart from the write time: what DAV clients get back after single-occurrence edits in the app.
describe('Single-occurrence edits output', function () {
    it('writes the exceptions and EXDATEs of a timed series in its own zone exactly as before', function () {
        $utc = new \DateTimeZone('UTC');
        davRequest($this->user, 'PUT', $this->path.'berlin.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin', 'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'END:STANDARD', 'BEGIN:DAYLIGHT', 'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'END:DAYLIGHT', 'END:VTIMEZONE',
            'BEGIN:VEVENT', 'UID:berlin', 'DTSTAMP:20300101T000000Z', 'DTSTART;TZID=Europe/Berlin:20301014T090000',
            'DURATION:PT30M', 'RRULE:FREQ=DAILY;COUNT=5', 'SUMMARY:Standup', 'LOCATION:Room 1', 'DESCRIPTION:Daily',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT5M', 'DESCRIPTION:Soon', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);
        $update = fn (string $title, string $start, string $end, ?string $description, ?string $location) => new EventDTO(
            id: null, title: $title, description: $description, startDate: new \DateTime($start, $utc), endDate: new \DateTime($end, $utc),
            isAllDay: false, location: $location, recurrenceRule: null, recurrenceEnd: null, timezone: 'UTC', uri: null, etag: null,
            calendarId: null, calendarName: null,
        );

        $this->events->updateOccurrence($this->calendar, 'berlin.ics', new \DateTime('2030-10-15 07:00:00', $utc), $update('Late', '2030-10-15 10:00:00', '2030-10-15 10:45:00', 'Moved by the app', null));
        $this->events->updateOccurrence($this->calendar, 'berlin.ics', new \DateTime('2030-10-16 07:00:00', $utc), $update('Other', '2030-10-16 07:00:00', '2030-10-16 07:30:00', null, 'Room 3'));
        $this->events->updateOccurrence($this->calendar, 'berlin.ics', new \DateTime('2030-10-15 10:00:00', $utc), $update('Later', '2030-10-15 11:00:00', '2030-10-15 11:45:00', '', 'Room 2'));
        $this->events->deleteOccurrence($this->calendar, 'berlin.ics', new \DateTime('2030-10-16 07:00:00', $utc));
        $this->events->deleteOccurrence($this->calendar, 'berlin.ics', new \DateTime('2030-10-17 07:00:00', $utc));
        $stored = davRequest($this->user, 'GET', $this->path.'berlin.ics')->getBodyAsString();

        expect(preg_replace('/DTSTAMP:(?!20300101T000000Z)\d{8}T\d{6}Z/', 'DTSTAMP:written', $stored))->toBe(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin',
            'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100', 'END:STANDARD', 'BEGIN:DAYLIGHT', 'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'END:DAYLIGHT',
            'END:VTIMEZONE', 'BEGIN:VEVENT', 'UID:berlin', 'DTSTAMP:20300101T000000Z',
            'DTSTART;TZID=Europe/Berlin:20301014T090000', 'DURATION:PT30M', 'RRULE:FREQ=DAILY;COUNT=5',
            'SUMMARY:Standup', 'LOCATION:Room 1', 'DESCRIPTION:Daily', 'EXDATE;TZID=Europe/Berlin:20301016T090000',
            'EXDATE;TZID=Europe/Berlin:20301017T090000', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT5M',
            'DESCRIPTION:Soon', 'END:VALARM', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:berlin',
            'RECURRENCE-ID;TZID=Europe/Berlin:20301015T090000', 'SUMMARY:Later', 'DTSTAMP:written',
            'DTSTART;TZID=Europe/Berlin:20301015T130000', 'DTEND;TZID=Europe/Berlin:20301015T134500', 'LOCATION:Room 2',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT5M', 'DESCRIPTION:Soon', 'END:VALARM', 'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n");
    });

    it('writes the exception and EXDATEs of an all-day series as floating dates exactly as before', function () {
        $utc = new \DateTimeZone('UTC');
        davRequest($this->user, 'PUT', $this->path.'weekly.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:weekly', 'DTSTAMP:20300101T000000Z',
            'DTSTART;VALUE=DATE:20301007', 'DTEND;VALUE=DATE:20301008', 'RRULE:FREQ=WEEKLY;COUNT=4', 'SUMMARY:Bin day', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n", ['Content-Type' => 'text/calendar']);
        $stored = fn () => preg_replace('/DTSTAMP:(?!20300101T000000Z)\d{8}T\d{6}Z/', 'DTSTAMP:written', davRequest($this->user, 'GET', $this->path.'weekly.ics')->getBodyAsString());

        $this->events->updateOccurrence($this->calendar, 'weekly.ics', new \DateTime('2030-10-14 00:00:00', $utc), new EventDTO(
            id: null, title: 'Late bins', description: 'Street', startDate: new \DateTime('2030-10-15 00:00:00', $utc),
            endDate: new \DateTime('2030-10-16 00:00:00', $utc), isAllDay: true, location: null, recurrenceRule: null, recurrenceEnd: null,
            timezone: 'UTC', uri: null, etag: null, calendarId: null, calendarName: null,
        ));

        expect($stored())->toBe(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:weekly',
            'DTSTAMP:20300101T000000Z', 'DTSTART;VALUE=DATE:20301007', 'DTEND;VALUE=DATE:20301008',
            'RRULE:FREQ=WEEKLY;COUNT=4', 'SUMMARY:Bin day', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:weekly',
            'RECURRENCE-ID;VALUE=DATE:20301014', 'SUMMARY:Late bins', 'DTSTAMP:written', 'DTSTART;VALUE=DATE:20301015',
            'DTEND;VALUE=DATE:20301017', 'DESCRIPTION:Street', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n");

        $this->events->deleteOccurrence($this->calendar, 'weekly.ics', new \DateTime('2030-10-21 00:00:00', $utc));
        $this->events->deleteOccurrence($this->calendar, 'weekly.ics', new \DateTime('2030-10-15 00:00:00', $utc));

        expect($stored())->toBe(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', 'UID:weekly',
            'DTSTAMP:20300101T000000Z', 'DTSTART;VALUE=DATE:20301007', 'DTEND;VALUE=DATE:20301008',
            'RRULE:FREQ=WEEKLY;COUNT=4', 'SUMMARY:Bin day', 'EXDATE;VALUE=DATE:20301021', 'EXDATE;VALUE=DATE:20301014',
            'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n");
    });
});
