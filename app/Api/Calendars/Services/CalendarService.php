<?php

namespace App\Api\Calendars\Services;

use App\Api\Calendars\DTOs\EventListingDTO;
use App\Api\Calendars\Exceptions\CalendarAlreadyExistsException;
use App\Api\Calendars\Exceptions\CalendarInviteNotFoundException;
use App\Api\Calendars\Exceptions\CalendarNotFoundException;
use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Calendars\Notifications\CalendarShareNotification;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Exceptions\ExternalCalendarPreconditionFailedException;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Exceptions\UnreadableCalendarObjectException;
use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CalendarService
{
    // calendarinstances.displayname is VARCHAR(100) on pgsql.
    public const int MAX_NAME_LENGTH = 100;
    // _v2: lists cached before CalendarDTO gained solytoShared/isExternal/isUnavailable/isCredentialsRejected
    // unserialize with those typed properties uninitialised.
    private const string CACHE_KEY_CALENDARS = 'calendars_v2';
    private const string CACHE_KEY_EVENTS = 'calendar_events';
    private const string CACHE_KEY_UNAVAILABLE = 'external_calendar_unavailable';
    // Owned by AppCalendarsPDO (the remote's ctag and sync token); forgotten here with the flag.
    private const string CACHE_KEY_STATE = 'external_calendar_state';
    private const string REASON_CREDENTIALS_REJECTED = 'credentials_rejected';
    private const int CACHE_TTL = 86400;
    private const int CACHE_TTL_EVENTS_PAST = 604800;
    private const int CACHE_TTL_EVENTS_CURRENT = 900;
    private const int CACHE_TTL_EVENTS_FUTURE = 3600;

    public function __construct(
        private readonly DavService $dav,
        private readonly UserCacheService $cache
    ) {}

    // Every cached event listing carries these tags; flushing the calendar tag drops the user's listings of that calendar, flushing the user tag all of them.
    /** @return array<int, string> */
    public static function eventCacheTags(string $userId, int $calendarId): array
    {
        return [UserCacheService::userTag($userId), self::eventCacheTag($userId, $calendarId)];
    }

    public static function eventCacheTag(string $userId, int $calendarId): string
    {
        return self::CACHE_KEY_EVENTS.'_'.$userId.'_'.$calendarId;
    }

    // AppCalendarsPDO reads and writes the unavailable flag with these tags too.
    /** @return array<int, string> */
    public static function unavailableCacheTags(string $userId): array
    {
        return [UserCacheService::userTag($userId)];
    }

    public function list(User $user): array
    {
        $calendars = $this->cache->remember([self::CACHE_KEY_CALENDARS, $user->id], self::CACHE_TTL,
            fn() => $this->dav->calendars()->list($user));

        return array_map(function (CalendarDTO $calendar) use ($user) {
            $reason = $calendar->isExternal ? $this->cache->getTagged(self::unavailableCacheTags($user->id), [self::CACHE_KEY_UNAVAILABLE, $user->id, $calendar->calendarId]) : null;
            if (!$reason) {
                return $calendar;
            }

            $calendar = clone $calendar;
            $calendar->isUnavailable = true;
            $calendar->isCredentialsRejected = $reason === self::REASON_CREDENTIALS_REJECTED;

            return $calendar;
        }, $calendars);
    }

    public function get(User $user, int $instanceId): ?CalendarDTO
    {
        return $this->dav->calendars()->get($user, $instanceId);
    }

    /** @throws CalendarNotFoundException */
    public function getOrFail(User $user, int $instanceId): CalendarDTO
    {
        return $this->get($user, $instanceId) ?? throw new CalendarNotFoundException();
    }

    public function getByName(User $user, string $name): ?CalendarDTO
    {
        return $this->dav->calendars()->getByName($user, $name);
    }

    /** @throws CalendarAlreadyExistsException */
    public function create(User $user, array $data): CalendarDTO
    {
        if ($this->getByName($user, $data['name']) !== null) {
            throw new CalendarAlreadyExistsException();
        }

        $calendar = $this->dav->calendars()->create($user, CalendarDTO::fromRequest($data));
        $this->forgetCalendarList($user->id);
        return $calendar;
    }

    public function update(User $user, int $instanceId, CalendarDTO $calendar): void
    {
        $this->dav->calendars()->update($user, $instanceId, $calendar);
        // Cached events carry the calendar's colour.
        $this->forgetCalendar($user->id, $calendar->calendarId);
    }

    // Called once a calendar is linked to a remote collection, so it is read as external from now on.
    public function markExternal(User $user, CalendarDTO $calendar): void
    {
        $this->dav->calendars()->forgetExternal($calendar->calendarId);
        $this->forgetCalendarList($user->id);
    }

    public function updateOrder(User $user, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $instanceId) {
            $this->dav->calendars()->updateOrder((int) $instanceId, $index);
        }
        $this->forgetCalendarList($user->id);
    }

    // Deleting the owner's instance removes every sharee instance with it, so the sharees are collected first.
    // solytoShared is not a usable guard: it ignores pending invites, which still show in the sharee's list.
    public function destroy(User $user, CalendarDTO $calendar): void
    {
        $canHaveSharees = ! $calendar->isShared && ! $calendar->isExternal;
        $shareeIds = $canHaveSharees
            ? array_column($this->dav->calendars()->sharing()->listSharees($calendar), 'user_id')
            : [];

        $this->dav->calendars()->delete($calendar);

        foreach ([$user->id, ...$shareeIds] as $userId) {
            $this->forgetCalendar($userId, $calendar->calendarId);
        }
    }

    public function listEvents(User $user, string $yearMonth): EventListingDTO
    {
        $now = date('Y-m');
        $ttl = match(true) {
            $yearMonth < $now   => self::CACHE_TTL_EVENTS_PAST,
            $yearMonth === $now => self::CACHE_TTL_EVENTS_CURRENT,
            default             => self::CACHE_TTL_EVENTS_FUTURE,
        };

        return $this->collectEvents(
            $user,
            [$yearMonth],
            $ttl,
            new \DateTime($yearMonth . '-01 00:00:00')->modify('- 3 months'),
            new \DateTime($yearMonth . '-01 23:59:59')->modify('last day of this month')->modify('+ 3 months')
        );
    }

    public function listWidgetEvents(User $user): EventListingDTO
    {
        $today = Carbon::today($user->timezone());

        return $this->collectEvents(
            $user,
            ['widget', $today->toDateString()],
            self::CACHE_TTL_EVENTS_FUTURE,
            $today->toDateTime(),
            $today->copy()->addDays(3)->toDateTime()
        );
    }

    /** @return array<int, EventDTO> */
    public function listTodayEvents(User $user): array
    {
        $today = Carbon::today($user->timezone());

        return $this->collectEvents(
            $user,
            ['today', $today->toDateString()],
            self::CACHE_TTL_EVENTS_CURRENT,
            $today->toDateTime(),
            $today->copy()->addDay()->toDateTime()
        )->events;
    }

    // Also drops the link with its account that a long-lived process holds, e.g. once the account has working credentials again.
    public function forgetRemoteState(string $userId, int $calendarId): void
    {
        $this->dav->calendars()->forgetExternal($calendarId);
        $this->cache->forgetTagged(self::unavailableCacheTags($userId), [self::CACHE_KEY_UNAVAILABLE, $userId, $calendarId]);
        $this->cache->forget([self::CACHE_KEY_STATE, $userId, $calendarId]);
        $this->cache->flushTags([self::eventCacheTag($userId, $calendarId)]);
    }

    /** @param  array<int, string>  $keySuffix */
    private function collectEvents(User $user, array $keySuffix, int $ttl, \DateTime $start, \DateTime $end): EventListingDTO
    {
        $calendars = array_filter($this->list($user), fn($c) => !$c->isPendingInvite());
        $events = [];
        $unavailable = [];
        $credentialsRejected = [];

        foreach ($calendars as $calendar) {
            if (!$calendar->isExternal) {
                $events = array_merge($events, $this->cache->rememberTagged(
                    self::eventCacheTags($user->id, $calendar->calendarId),
                    [self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId, ...$keySuffix],
                    $ttl,
                    fn() => $this->dav->calendars()->events()->listExpanded($calendar, $start, $end)
                ));
                continue;
            }

            $external = $this->externalEvents($user, $calendar, $keySuffix, $start, $end);
            $events = array_merge($events, $external['events']);

            if ($external['unavailable_reason'] !== null) {
                $unavailable[] = $calendar->instanceId;
            }
            // A flag stored before reasons were (a timestamp) reads as unreachable.
            if ($external['unavailable_reason'] === self::REASON_CREDENTIALS_REJECTED) {
                $credentialsRejected[] = $calendar->instanceId;
            }
        }

        return new EventListingDTO($events, $unavailable, $credentialsRejected);
    }

    // Remote changes never invalidate an external calendar's cache, so its entries live for the short external TTL.
    // An unreachable one is not asked again for the unavailable TTL, whether the failure was seen here or by a DAV
    // client (AppCalendarsPDO shares the key); meanwhile its events are served from the cache when they are there.
    /**
     * @param  array<int, string>  $keySuffix
     * @return array{events: array<int, EventDTO>, unavailable_reason: mixed}
     */
    private function externalEvents(User $user, CalendarDTO $calendar, array $keySuffix, \DateTime $start, \DateTime $end): array
    {
        $unavailableKey = [self::CACHE_KEY_UNAVAILABLE, $user->id, $calendar->calendarId];
        $eventsTags = self::eventCacheTags($user->id, $calendar->calendarId);
        $eventsKey = [self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId, ...$keySuffix];

        // Wrapped so an empty window is cached too: the cache treats
        // a bare [] as a miss and would ask the remote every time.
        $cached = $this->cache->getTagged($eventsTags, $eventsKey);
        $isCached = is_array($cached) && array_key_exists('events', $cached);

        $reason = $this->cache->getTagged(self::unavailableCacheTags($user->id), $unavailableKey);
        if ($reason) {
            return ['events' => $isCached ? $cached['events'] : [], 'unavailable_reason' => $reason];
        }

        try {
            if (!$isCached) {
                $cached = ['events' => $this->dav->calendars()->events()->listExpanded($calendar, $start, $end)];
                $this->cache->storeTagged($eventsTags, $eventsKey, (int) config('calendars.external.cache_ttl'), $cached);
            }

            return ['events' => $cached['events'], 'unavailable_reason' => null];
        } catch (ExternalCalendarUnavailableException $e) {
            $this->cache->storeTagged(self::unavailableCacheTags($user->id), $unavailableKey, (int) config('calendars.external.unavailable_ttl'), $e->reason());

            return ['events' => [], 'unavailable_reason' => $e->reason()];
        }
    }

    public function getEvent(CalendarDTO $calendar, string $eventUri): ?EventDTO
    {
        return $this->dav->calendars()->events()->get($calendar, $eventUri);
    }

    public function createEvent(User $user, CalendarDTO $calendar, EventDTO $dto): ?EventDTO
    {
        $event = $this->dav->calendars()->events()->create($calendar, $dto);
        $this->cache->flushTags([self::eventCacheTag($user->id, $calendar->calendarId)]);
        if ($event !== null) {
            $this->invalidateSharedCalendar($calendar);
        }
        return $event;
    }

    public function moveEvent(User $user, CalendarDTO $from, CalendarDTO $to, EventDTO $event): ?EventDTO
    {
        $newEvent = $this->dav->calendars()->events()->create($to, $event);
        if ($newEvent === null) {
            return null;
        }

        // Create first so a failed create loses nothing; when the source
        // (an external remote) refuses the delete, the copy is taken back so
        // a retry does not leave a duplicate.
        try {
            $this->dav->calendars()->events()->delete($from, $event);
        } catch (\Throwable $e) {
            $this->removeMovedCopy($to, $newEvent);
            throw $e;
        } finally {
            // Both caches first: a failing queue dispatch must not leave the target calendar stale.
            $this->cache->flushTags([self::eventCacheTag($user->id, $from->calendarId)]);
            $this->cache->flushTags([self::eventCacheTag($user->id, $to->calendarId)]);
            $this->invalidateSharedCalendar($from);
            $this->invalidateSharedCalendar($to);
        }

        return $newEvent;
    }

    // A failure here is only logged: the caller rethrows the delete's error, which is the one the user needs to see.
    private function removeMovedCopy(CalendarDTO $to, EventDTO $copy): void
    {
        try {
            $this->dav->calendars()->events()->delete($to, $copy);
        } catch (\Throwable $e) {
            Log::channel('dav')->warning('Moved event copy could not be removed after the source delete failed', [
                'calendar_id' => $to->calendarId,
                'uri' => $copy->uri,
                'error' => $e::class,
            ]);
        }
    }

    public function updateEvent(User $user, CalendarDTO $calendar, EventDTO $event): ?EventDTO
    {
        $updated = $this->forgettingStaleEvents($user, $calendar, fn () => $this->dav->calendars()->events()->update($calendar, $event));
        if ($updated !== null) {
            $this->forgetEvents($user, $calendar);
        }
        return $updated;
    }

    public function destroyEvent(User $user, CalendarDTO $calendar, EventDTO $event): bool
    {
        $success = $this->forgettingStaleEvents($user, $calendar, fn () => $this->dav->calendars()->events()->delete($calendar, $event));
        if ($success) {
            $this->forgetEvents($user, $calendar);
        }
        return $success;
    }

    public function destroyEventOccurrence(User $user, CalendarDTO $calendar, string $eventUri, \DateTime $date): bool
    {
        $success = $this->forgettingStaleEvents($user, $calendar, fn () => $this->dav->calendars()->events()->deleteOccurrence($calendar, $eventUri, $date));
        if ($success) {
            $this->forgetEvents($user, $calendar);
        }
        return $success;
    }

    public function updateEventOccurrence(User $user, CalendarDTO $calendar, string $eventUri, \DateTime $date, EventDTO $dto): bool
    {
        $success = $this->forgettingStaleEvents($user, $calendar, fn () => $this->dav->calendars()->events()->updateOccurrence($calendar, $eventUri, $date, $dto));
        if ($success) {
            $this->forgetEvents($user, $calendar);
        }
        return $success;
    }

    // A 412 or an unreadable object means the cached copy is stale (an external remote changed it),
    // so it is dropped before the error reaches the client: the reload then shows the remote's version.
    /**
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    private function forgettingStaleEvents(User $user, CalendarDTO $calendar, \Closure $write): mixed
    {
        try {
            return $write();
        } catch (ExternalCalendarPreconditionFailedException|UnreadableCalendarObjectException $e) {
            $this->cache->flushTags([self::eventCacheTag($user->id, $calendar->calendarId)]);

            throw $e;
        }
    }

    /** @throws AuthorizationException */
    public function share(CalendarDTO $calendar, User $owner, User $recipient): void
    {
        $this->assertShareable($calendar);
        $this->dav->calendars()->sharing()->inviteUser($calendar, $owner, $recipient);
        $this->forgetCalendarList($recipient->id);
        $recipient->notify(new CalendarShareNotification($calendar->displayName, $owner->name));
    }

    /** @throws AuthorizationException */
    public function listSharees(CalendarDTO $calendar): array
    {
        $this->assertShareable($calendar);

        return $this->dav->calendars()->sharing()->listSharees($calendar);
    }

    /** @throws AuthorizationException */
    public function revokeShare(User $owner, CalendarDTO $calendar, User $recipient): void
    {
        $this->assertShareable($calendar);
        $this->dav->calendars()->sharing()->revokeShare($calendar->calendarId, $recipient);
        $this->forgetCalendarList($owner->id);
        $this->forgetCalendar($recipient->id, $calendar->calendarId);
    }

    /** @throws AuthorizationException */
    private function assertShareable(CalendarDTO $calendar): void
    {
        if ($calendar->isExternal) {
            throw new AuthorizationException('External calendars cannot be shared.');
        }
    }

    public function unsubscribe(User $user, CalendarDTO $calendar): void
    {
        $ownerId = $this->dav->calendars()->sharing()->ownerId($calendar->calendarId);

        $this->dav->calendars()->delete($calendar);
        $this->forgetCalendarList($ownerId);
        $this->forgetCalendar($user->id, $calendar->calendarId);
    }

    public function listInvites(User $user): \Illuminate\Support\Collection
    {
        return $this->dav->calendars()->sharing()->listInvites($user);
    }

    /** @throws CalendarInviteNotFoundException */
    public function acceptInvite(User $user, string $token): void
    {
        $calendarId = $this->dav->calendars()->sharing()->acceptInvite($user, $token);

        if ($calendarId === null) {
            throw new CalendarInviteNotFoundException();
        }

        $this->forgetCalendarList($this->dav->calendars()->sharing()->ownerId($calendarId));
        $this->forgetCalendarList($user->id);
    }

    /** @throws CalendarInviteNotFoundException */
    public function declineInvite(User $user, string $token): void
    {
        if (! $this->dav->calendars()->sharing()->declineInvite($user, $token)) {
            throw new CalendarInviteNotFoundException();
        }

        $this->forgetCalendarList($user->id);
    }

    private function invalidateSharedCalendar(CalendarDTO $calendar): void
    {
        if ($calendar->isShared || $calendar->solytoShared) {
            InvalidateSharedCalendarCache::dispatch($calendar->calendarId);
        }
    }

    // Takes a share's owner too, who may be gone: the owner's cached list carries solytoShared, which a share change flips.
    private function forgetCalendarList(?string $userId): void
    {
        if ($userId !== null) {
            $this->cache->forget([self::CACHE_KEY_CALENDARS, $userId]);
        }
    }

    private function forgetCalendar(string $userId, int $calendarId): void
    {
        $this->forgetCalendarList($userId);
        $this->cache->flushTags([self::eventCacheTag($userId, $calendarId)]);
    }

    private function forgetEvents(User $user, CalendarDTO $calendar): void
    {
        $this->cache->flushTags([self::eventCacheTag($user->id, $calendar->calendarId)]);
        $this->invalidateSharedCalendar($calendar);
    }
}
