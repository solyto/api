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
