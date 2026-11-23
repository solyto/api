<?php

use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Calendars\Services\CalendarService;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
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
        Queue::fake();
    });

    it('clears the owner and accepted sharees only and queues nothing', function () {
        (new InvalidateSharedCalendarCache($this->calendar->calendarId))->handle(app(DavService::class), $this->cache);

        foreach ([$this->owner, $this->accepted] as $member) {
            $this->cache->shouldHaveReceived('flushTags')->with([CalendarService::eventCacheTag($member->id, $this->calendar->calendarId)])->once();
        }

        foreach ([$this->pending, $this->unrelated] as $outsider) {
            $this->cache->shouldNotHaveReceived('flushTags', [[CalendarService::eventCacheTag($outsider->id, $this->calendar->calendarId)]]);
        }
        Queue::assertNothingPushed();
    });

    it('shows a change behind the cache to the owner and accepted sharees and leaves others cached', function () {
        $dav = app(DavService::class);
        $calendars = app(CalendarService::class);
        $outsiderCalendar = $dav->calendars()->list($this->unrelated)[0];
        $add = fn (User $user, CalendarDTO $calendar, string $title) => $dav->calendars()->events()->create($calendar, EventDTO::fromRequest([
            'title' => $title,
            'start_date' => '2030-10-10 15:00:00',
            'end_date' => '2030-10-10 16:00:00',
            'is_all_day' => false,
        ], $calendar, $user));
        $titles = fn (User $user) => collect($calendars->listEvents($user, '2030-10'))->pluck('title')->sort()->values()->all();
        $add($this->owner, $this->calendar, 'Seed');
        $add($this->unrelated, $outsiderCalendar, 'Seed');
        foreach ([$this->owner, $this->accepted, $this->unrelated] as $user) {
            expect($titles($user))->toBe(['Seed']);
        }
        $add($this->owner, $this->calendar, 'Behind the cache');
        $add($this->unrelated, $outsiderCalendar, 'Behind the cache');

        (new InvalidateSharedCalendarCache($this->calendar->calendarId))->handle($dav, app(UserCacheService::class));

        expect($titles($this->owner))->toBe(['Behind the cache', 'Seed'])
            ->and($titles($this->accepted))->toBe(['Behind the cache', 'Seed'])
            ->and($titles($this->unrelated))->toBe(['Seed']);
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
