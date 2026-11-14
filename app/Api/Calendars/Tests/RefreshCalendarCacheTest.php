<?php

use App\Api\Calendars\Jobs\RefreshCalendarCache;
use App\Api\Calendars\Services\CalendarService;
use App\Dav\DTOs\EventDTO;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

function refreshCacheEvent(string $uid): string
{
    $start = Carbon::now('UTC')->startOfMonth()->addDays(9)->setTime(10, 0);

    return davIcs($uid, ucfirst($uid), $start->format('Ymd\THis\Z'), $start->copy()->addHour()->format('Ymd\THis\Z'));
}

describe('RefreshCalendarCache', function () {
    // The race: a run reads DAV, a write lands and queues the next run, then
    // the first run stores its pre-write result. Here the write's own
    // invalidation is out of the way (Queue::fake, forgetByPrefix is a no-op
    // on the array store), so the entry from before the write is still there.
    it('overwrites entries cached before a write', function () {
        Queue::fake();
        $user = davUser();
        $service = app(CalendarService::class);
        expect(davRequest($user, 'PUT', 'calendars/'.$user->email.'/my-calendar/early.ics', refreshCacheEvent('early'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(201);

        $calendar = $service->list($user)[0];
        $service->listEvents($user, date('Y-m'));
        $cache = app(UserCacheService::class);
        $staleCalendar = clone $calendar;
        $staleCalendar->displayName = 'Stale';
        $cache->store(['calendars', $user->id], 86400, [$staleCalendar]);

        expect(davRequest($user, 'PUT', 'calendars/'.$user->email.'/my-calendar/late.ics', refreshCacheEvent('late'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(201);

        (new RefreshCalendarCache($user->id))->handle($service);

        $events = $cache->get(['calendar_events', $user->id, $calendar->calendarId, date('Y-m')]);
        expect($cache->get(['calendars', $user->id])[0]->displayName)->toBe($calendar->displayName)
            ->and(array_map(fn (EventDTO $event) => $event->title, $events))->toEqualCanonicalizing(['Early', 'Late']);
    });
});
