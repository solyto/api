<?php

namespace App\Api\Calendars\Jobs;

use App\Api\Calendars\Services\CalendarService;
use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Events are cached per user, so a write to a shared calendar leaves the
 * other members' caches stale. One pending run per calendar; the editor is
 * cleared again too, since one run can cover writes by several members.
 */
class InvalidateSharedCalendarCache implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable, Dispatchable, InteractsWithQueue;

    public int $uniqueFor = 60;

    public function __construct(
        public int $calendarId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->calendarId;
    }

    public function handle(DavService $dav, UserCacheService $cache): void
    {
        foreach ($dav->calendars()->sharing()->listMemberIds($this->calendarId) as $userId) {
            $cache->forgetByPrefix([CalendarService::CACHE_KEY_EVENTS, $userId, $this->calendarId]);
            RefreshCalendarCache::dispatch($userId);
        }
    }
}
