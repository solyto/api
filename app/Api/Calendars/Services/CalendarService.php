<?php

namespace App\Api\Calendars\Services;

use App\Api\Calendars\Exceptions\CalendarAlreadyExistsException;
use App\Api\Calendars\Exceptions\CalendarInviteNotFoundException;
use App\Api\Calendars\Exceptions\CalendarNotFoundException;
use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CalendarService
{
    private const string CACHE_KEY_CALENDARS = 'calendars';
    public const string CACHE_KEY_EVENTS = 'calendar_events';
    private const string CACHE_KEY_UNAVAILABLE = 'external_calendar_unavailable';
    private const int CACHE_TTL = 86400;
    private const int CACHE_TTL_EVENTS_PAST = 604800;
    private const int CACHE_TTL_EVENTS_CURRENT = 900;
    private const int CACHE_TTL_EVENTS_FUTURE = 3600;

    /** @var array<int, int> */
    private array $unavailable = [];

    public function __construct(
        private readonly DavService $dav,
        private readonly UserCacheService $cache
    ) {}

    public function list(User $user): array
    {
        $calendars = $this->cache->remember([self::CACHE_KEY_CALENDARS, $user->id], self::CACHE_TTL,
            fn() => $this->dav->calendars()->list($user));

        return array_map(function (CalendarDTO $calendar) use ($user) {
            if (!$calendar->isExternal || !$this->cache->get([self::CACHE_KEY_UNAVAILABLE, $user->id, $calendar->calendarId])) {
                return $calendar;
            }

            $calendar = clone $calendar;
            $calendar->isUnavailable = true;

            return $calendar;
        }, $calendars);
    }

    public function get(User $user, int $instanceId): ?CalendarDTO
    {
        return $this->dav->calendars()->get($user, $instanceId);
    }

    /**
     * @throws CalendarNotFoundException
     */
    public function getOrFail(User $user, int $instanceId): CalendarDTO
    {
        return $this->get($user, $instanceId) ?? throw new CalendarNotFoundException();
    }

    public function getByName(User $user, string $name): ?CalendarDTO
    {
        return $this->dav->calendars()->getByName($user, $name);
    }

    /**
     * @throws CalendarAlreadyExistsException
     */
    public function create(User $user, array $data): CalendarDTO
    {
        if ($this->getByName($user, $data['name']) !== null) {
            throw new CalendarAlreadyExistsException();
        }

        $calendar = $this->dav->calendars()->create($user, CalendarDTO::fromRequest($data));
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
        return $calendar;
    }

    public function update(User $user, int $instanceId, CalendarDTO $calendar): void
    {
        $this->dav->calendars()->update($user, $instanceId, $calendar);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    /**
     * Called once a calendar was linked to a remote collection, so it is
     * read as external from now on.
     */
    public function markExternal(User $user, CalendarDTO $calendar): void
    {
        $this->dav->calendars()->forgetExternal($calendar->calendarId);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    public function updateOrder(User $user, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $instanceId) {
            $this->dav->calendars()->updateOrder((int) $instanceId, $index);
        }
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    /**
     * Deleting the owner's instance removes every sharee instance with it, so
     * the sharees are collected first. solytoShared is not a usable guard: it
     * ignores pending invites, which still show in the sharee's list.
     */
    public function destroy(User $user, CalendarDTO $calendar): void
    {
        $shareeIds = ! $calendar->isShared && ! $calendar->isExternal
            ? array_column($this->dav->calendars()->sharing()->listSharees($calendar), 'user_id')
            : [];

        $this->dav->calendars()->delete($calendar);

        foreach ([$user->id, ...$shareeIds] as $userId) {
            $this->cache->forget([self::CACHE_KEY_CALENDARS, $userId]);
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $userId, $calendar->calendarId]);
        }
    }

    public function listEvents(User $user, string $yearMonth): array
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

    public function listWidgetEvents(User $user): array
    {
        $today = Carbon::today($user->settings->timezone ?? 'UTC');

        return $this->collectEvents(
            $user,
            ['widget', $today->toDateString()],
            self::CACHE_TTL_EVENTS_FUTURE,
            $today->toDateTime(),
            $today->copy()->addDays(3)->toDateTime()
        );
    }

    /**
     * @return array<int, EventDTO>
     */
    public function listTodayEvents(User $user): array
    {
        $today = Carbon::today($user->settings->timezone ?? 'UTC');

        return $this->collectEvents(
            $user,
            ['today', $today->toDateString()],
            self::CACHE_TTL_EVENTS_CURRENT,
            $today->toDateTime(),
            $today->copy()->addDay()->toDateTime()
        );
    }

    /**
     * Instance ids of the external calendars the last event listing had to
     * leave out because their server was unreachable.
     *
     * @return array<int, int>
     */
    public function unavailableCalendars(): array
    {
        return $this->unavailable;
    }

    /**
     * Remote changes never invalidate the cache of an external calendar, so
     * its entries live for the short external TTL. An unreachable external
     * calendar is left out and reported, and flagged in list() until a
     * listing reaches it again; the failure itself is not cached.
     *
     * @param  array<int, string>  $keySuffix
     * @return array<int, EventDTO>
     */
    private function collectEvents(User $user, array $keySuffix, int $ttl, \DateTime $start, \DateTime $end): array
    {
        $calendars = array_filter($this->list($user), fn($c) => $c->inviteStatus !== 'pending');
        $this->unavailable = [];
        $events = [];

        foreach ($calendars as $calendar) {
            if (!$calendar->isExternal) {
                $events = array_merge($events, $this->cache->remember(
                    [self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId, ...$keySuffix],
                    $ttl,
                    fn() => $this->dav->calendars()->events()->listExpanded($calendar, $start, $end)
                ));
                continue;
            }

            $externalTtl = (int) config('calendars.external.cache_ttl');
            $unavailableKey = [self::CACHE_KEY_UNAVAILABLE, $user->id, $calendar->calendarId];

            $eventsKey = [self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId, ...$keySuffix];

            try {
                // Wrapped so an empty window is cached too: the cache treats
                // a bare [] as a miss and would ask the remote every time.
                $cached = $this->cache->get($eventsKey);
                if (!is_array($cached) || !array_key_exists('events', $cached)) {
                    $cached = ['events' => $this->dav->calendars()->events()->listExpanded($calendar, $start, $end)];
                    $this->cache->store($eventsKey, $externalTtl, $cached);
                }
                $events = array_merge($events, $cached['events']);
                $this->cache->forget($unavailableKey);
            } catch (ExternalCalendarUnavailableException) {
                $this->unavailable[] = $calendar->instanceId;
                $this->cache->store($unavailableKey, $externalTtl, time());
            }
        }

        return $events;
    }

    public function getEvent(CalendarDTO $calendar, string $eventUri): ?EventDTO
    {
        return $this->dav->calendars()->events()->get($calendar, $eventUri);
    }

    public function createEvent(User $user, CalendarDTO $calendar, EventDTO $dto): ?EventDTO
    {
        $event = $this->dav->calendars()->events()->create($calendar, $dto);
        $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
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
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $from->calendarId]);
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $to->calendarId]);
            $this->invalidateSharedCalendar($from);
            $this->invalidateSharedCalendar($to);
        }

        return $newEvent;
    }

    /**
     * A failure here is only logged: the caller rethrows the delete's error,
     * which is the one the user needs to see.
     */
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
        $updated = $this->dav->calendars()->events()->update($calendar, $event);
        if ($updated !== null) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
            $this->invalidateSharedCalendar($calendar);
        }
        return $updated;
    }

    public function destroyEvent(User $user, CalendarDTO $calendar, EventDTO $event): bool
    {
        $success = $this->dav->calendars()->events()->delete($calendar, $event);
        if ($success) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
            $this->invalidateSharedCalendar($calendar);
        }
        return $success;
    }

    public function destroyEventOccurrence(User $user, CalendarDTO $calendar, string $eventUri, \DateTime $date): bool
    {
        $success = $this->dav->calendars()->events()->deleteOccurrence($calendar, $eventUri, $date);
        if ($success) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
            $this->invalidateSharedCalendar($calendar);
        }
        return $success;
    }

    public function updateEventOccurrence(User $user, CalendarDTO $calendar, string $eventUri, \DateTime $date, EventDTO $dto): bool
    {
        $success = $this->dav->calendars()->events()->updateOccurrence($calendar, $eventUri, $date, $dto);
        if ($success) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
            $this->invalidateSharedCalendar($calendar);
        }
        return $success;
    }

    /**
     * @throws AuthorizationException
     */
    public function share(CalendarDTO $calendar, User $owner, User $recipient): void
    {
        $this->assertShareable($calendar);
        $this->dav->calendars()->sharing()->inviteUser($calendar, $owner, $recipient);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $recipient->id]);
    }

    /**
     * @throws AuthorizationException
     */
    public function listSharees(CalendarDTO $calendar): array
    {
        $this->assertShareable($calendar);

        return $this->dav->calendars()->sharing()->listSharees($calendar);
    }

    /**
     * @throws AuthorizationException
     */
    public function revokeShare(User $owner, CalendarDTO $calendar, User $recipient): void
    {
        $this->assertShareable($calendar);
        $this->dav->calendars()->sharing()->revokeShare($calendar->calendarId, $recipient);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $owner->id]);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $recipient->id]);
        $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $recipient->id, $calendar->calendarId]);
    }

    /**
     * @throws AuthorizationException
     */
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
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
        $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
    }

    public function listInvites(User $user): \Illuminate\Support\Collection
    {
        return $this->dav->calendars()->sharing()->listInvites($user);
    }

    /**
     * @throws CalendarInviteNotFoundException
     */
    public function acceptInvite(User $user, string $token): void
    {
        $calendarId = $this->dav->calendars()->sharing()->acceptInvite($user, $token);

        if ($calendarId === null) {
            throw new CalendarInviteNotFoundException();
        }

        $this->forgetCalendarList($this->dav->calendars()->sharing()->ownerId($calendarId));
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    /**
     * @throws CalendarInviteNotFoundException
     */
    public function declineInvite(User $user, string $token): void
    {
        if (! $this->dav->calendars()->sharing()->declineInvite($user, $token)) {
            throw new CalendarInviteNotFoundException();
        }

        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    private function invalidateSharedCalendar(CalendarDTO $calendar): void
    {
        if ($calendar->isShared || $calendar->solytoShared) {
            InvalidateSharedCalendarCache::dispatch($calendar->calendarId);
        }
    }

    /**
     * The owner's cached list carries solytoShared, which a share change flips.
     */
    private function forgetCalendarList(?string $userId): void
    {
        if ($userId !== null) {
            $this->cache->forget([self::CACHE_KEY_CALENDARS, $userId]);
        }
    }
}
