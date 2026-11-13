<?php

use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Calendars\Jobs\RefreshCalendarCache;
use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Queue;

describe('InvalidateSharedCalendarCache', function () {
    beforeEach(function () {
        $this->owner = makeUser();
        $this->accepted = makeUser();
        $this->pending = makeUser();
        $this->unrelated = makeUser();

        $dav = app(DavService::class);
        $this->calendar = $dav->calendars()->list($this->owner)[0];
        $sharing = $dav->calendars()->sharing();
        $sharing->inviteUser($this->calendar, $this->owner, $this->accepted);
        $sharing->inviteUser($this->calendar, $this->owner, $this->pending);
        $sharing->acceptInvite($this->accepted, 'mailto:'.$this->accepted->email);

        $this->cache = Mockery::spy(UserCacheService::class);
        Queue::fake([RefreshCalendarCache::class]);
    });

    it('clears and re-warms the owner and accepted sharees only', function () {
        (new InvalidateSharedCalendarCache($this->calendar->calendarId))->handle(app(DavService::class), $this->cache);

        foreach ([$this->owner, $this->accepted] as $member) {
            $this->cache->shouldHaveReceived('forgetByPrefix')->with(['calendar_events', $member->id, $this->calendar->calendarId])->once();
            Queue::assertPushed(RefreshCalendarCache::class, fn (RefreshCalendarCache $job) => $job->userId === $member->id);
        }

        foreach ([$this->pending, $this->unrelated] as $outsider) {
            $this->cache->shouldNotHaveReceived('forgetByPrefix', [['calendar_events', $outsider->id, $this->calendar->calendarId]]);
        }
        Queue::assertPushed(RefreshCalendarCache::class, 2);
    });

    it('queues one run per calendar', function () {
        Queue::fake([InvalidateSharedCalendarCache::class]);
        $other = app(DavService::class)->calendars()->list($this->unrelated)[0];

        InvalidateSharedCalendarCache::dispatch($this->calendar->calendarId);
        InvalidateSharedCalendarCache::dispatch($this->calendar->calendarId);
        InvalidateSharedCalendarCache::dispatch($other->calendarId);

        Queue::assertPushed(InvalidateSharedCalendarCache::class, fn ($job) => $job->calendarId === $this->calendar->calendarId);
        Queue::assertPushed(InvalidateSharedCalendarCache::class, 2);
    });
});
