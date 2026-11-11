<?php

use App\Api\Users\Models\User;
use App\Dav\Backend\AppCalendarsPDO;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Services\DavService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function rangeCalendar(User $user): CalendarDTO
{
    return app(DavService::class)->calendars()->list($user)[0];
}

function rangePut(User $user, string $uri, string $ics): void
{
    $response = davRequest($user, 'PUT', 'calendars/'.$user->email.'/my-calendar/'.$uri, $ics, ['Content-Type' => 'text/calendar']);

    expect($response->getStatus())->toBe(201);
}

function rangeAllDay(string $uid, string $summary, string $start, string $end): string
{
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20300101T000000Z',
        "DTSTART;VALUE=DATE:{$start}", "DTEND;VALUE=DATE:{$end}", "SUMMARY:{$summary}", 'END:VEVENT', 'END:VCALENDAR',
    ])."\r\n";
}

/**
 * @return array<int, string>
 */
function rangeTitles(CalendarDTO $calendar, \DateTime $start, \DateTime $end): array
{
    return collect(app(DavService::class)->calendars()->events()->listExpanded($calendar, $start, $end))
        ->map(fn (EventDTO $e) => $e->title)
        ->unique()
        ->sort()
        ->values()
        ->all();
}

describe('Reading events for a window', function () {
    beforeEach(function () {
        $this->user = davUser();
        $this->calendar = rangeCalendar($this->user);
        $this->start = new \DateTime('2030-10-14 00:00:00', new \DateTimeZone('UTC'));
        $this->end = new \DateTime('2030-10-17 00:00:00', new \DateTimeZone('UTC'));

        rangePut($this->user, 'inside.ics', davIcs('inside', 'Inside', '20301015T100000Z', '20301015T110000Z'));
        rangePut($this->user, 'last-year.ics', davIcs('last-year', 'Last year', '20291015T100000Z', '20291015T110000Z'));
        rangePut($this->user, 'infinite.ics', davIcs('infinite', 'Infinite', '20290101T080000Z', '20290101T083000Z', ['RRULE:FREQ=DAILY']));
        rangePut($this->user, 'finite.ics', davIcs('finite', 'Finite', '20300901T080000Z', '20300901T083000Z', ['RRULE:FREQ=DAILY;COUNT=45']));
        rangePut($this->user, 'ended.ics', davIcs('ended', 'Ended', '20300901T080000Z', '20300901T083000Z', ['RRULE:FREQ=DAILY;COUNT=10']));
        rangePut($this->user, 'todo.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN', 'BEGIN:VTODO', 'UID:todo', 'DTSTAMP:20300101T000000Z',
            'DTSTART:20301015T090000Z', 'DUE:20301015T120000Z', 'SUMMARY:Todo', 'END:VTODO', 'END:VCALENDAR',
        ])."\r\n");
    });

    it('reads only the VEVENTs that can overlap the window', function () {
        $uris = collect((new AppCalendarsPDO(DB::connection('pgsql')->getPdo()))->getEventObjectsInRange($this->calendar, $this->start, $this->end))
            ->pluck('uri')
            ->sort()
            ->values()
            ->all();

        expect($uris)->toBe(['finite.ics', 'infinite.ics', 'inside.ics']);
    });

    it('returns only the events in the window', function () {
        expect(rangeTitles($this->calendar, $this->start, $this->end))->toBe(['Finite', 'Infinite', 'Inside']);
    });
});

describe('All-day events at the window edges', function () {
    it('keeps the first and last day for a user far from UTC', function (string $timezone) {
        $user = davUser();
        $user->settings()->update(['timezone' => $timezone]);
        $calendar = rangeCalendar($user);

        rangePut($user, 'before.ics', rangeAllDay('before', 'Day before', '20301013', '20301014'));
        rangePut($user, 'first.ics', rangeAllDay('first', 'First day', '20301014', '20301015'));
        rangePut($user, 'last.ics', rangeAllDay('last', 'Last day', '20301016', '20301017'));
        rangePut($user, 'after.ics', rangeAllDay('after', 'Day after', '20301017', '20301018'));

        // Built like CalendarService::listWidgetEvents().
        $today = Carbon::parse('2030-10-14', $timezone);

        expect(rangeTitles($calendar, $today->toDateTime(), $today->copy()->addDays(3)->toDateTime()))
            ->toBe(['First day', 'Last day']);
    })->with(['Pacific/Kiritimati', 'Etc/GMT+12']);
});
