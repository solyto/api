<?php

namespace App\Api\Calendars\Services;

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;

class CalendarService
{
    private const string CACHE_KEY_CALENDARS = 'calendars';
    private const string CACHE_KEY_EVENTS = 'calendar_events';
    private const int CACHE_TTL = 86400;
    private const int CACHE_TTL_EVENTS_PAST = 604800;
    private const int CACHE_TTL_EVENTS_CURRENT = 900;
    private const int CACHE_TTL_EVENTS_FUTURE = 3600;

    public function __construct(
        private readonly DavService $dav,
        private readonly UserCacheService $cache
    ) {}

    public function list(User $user): array
    {
        return $this->cache->remember([self::CACHE_KEY_CALENDARS, $user->id], self::CACHE_TTL,
            fn() => $this->dav->calendars()->list($user));
    }

    public function get(User $user, int $instanceId): ?CalendarDTO
    {
        return $this->dav->calendars()->get($user, $instanceId);
    }

    public function getByName(User $user, string $name): ?CalendarDTO
    {
        return $this->dav->calendars()->getByName($user, $name);
    }

    public function create(User $user, array $data): CalendarDTO
    {
        $calendar = $this->dav->calendars()->create($user, CalendarDTO::fromRequest($data));
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
        return $calendar;
    }

    public function update(User $user, int $instanceId, CalendarDTO $calendar): void
    {
        $this->dav->calendars()->update($user, $instanceId, $calendar);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    public function updateOrder(User $user, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $instanceId) {
            $this->dav->calendars()->updateOrder((int) $instanceId, $index);
        }
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    public function destroy(User $user, CalendarDTO $calendar): void
    {
        $this->dav->calendars()->delete($calendar);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
        $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
    }

    public function listEvents(User $user, string $yearMonth): array
    {
        $calendars = $this->list($user);
        $now = date('Y-m');
        $ttl = match(true) {
            $yearMonth < $now   => self::CACHE_TTL_EVENTS_PAST,
            $yearMonth === $now => self::CACHE_TTL_EVENTS_CURRENT,
            default             => self::CACHE_TTL_EVENTS_FUTURE,
        };

        $events = [];
        foreach ($calendars as $calendar) {
            $events = array_merge($events, $this->cache->remember(
                [self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId, $yearMonth],
                $ttl,
                fn() => $this->dav->calendars()->events()->listExpanded(
                    $calendar,
                    new \DateTime($yearMonth . '-01 00:00:00')->modify('- 3 months'),
                    new \DateTime($yearMonth . '-01 23:59:59')->modify('last day of this month')->modify('+ 3 months')
                )
            ));
        }

        return $events;
    }

    public function listWidgetEvents(User $user): array
    {
        $calendars = $this->list($user);
        $today = date('Y-m-d');
        $events = [];

        foreach ($calendars as $calendar) {
            $events = array_merge($events, $this->cache->remember(
                [self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId, 'widget', $today],
                self::CACHE_TTL_EVENTS_FUTURE,
                fn() => $this->dav->calendars()->events()->listExpanded(
                    $calendar,
                    new \DateTime('today'),
                    new \DateTime('today')->modify('+ 3 days')
                )
            ));
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
        return $event;
    }

    public function moveEvent(User $user, CalendarDTO $from, CalendarDTO $to, EventDTO $event): ?EventDTO
    {
        $newEvent = $this->dav->calendars()->events()->create($to, $event);
        if ($newEvent === null) {
            return null;
        }
        $this->dav->calendars()->events()->delete($from, $event);
        $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $from->calendarId]);
        $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $to->calendarId]);
        return $newEvent;
    }

    public function updateEvent(User $user, CalendarDTO $calendar, EventDTO $event): ?EventDTO
    {
        $updated = $this->dav->calendars()->events()->update($calendar, $event);
        if ($updated !== null) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
        }
        return $updated;
    }

    public function destroyEvent(User $user, CalendarDTO $calendar, EventDTO $event): bool
    {
        $success = $this->dav->calendars()->events()->delete($calendar, $event);
        if ($success) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
        }
        return $success;
    }

    public function destroyEventOccurrence(User $user, CalendarDTO $calendar, string $eventUri, \DateTime $date): bool
    {
        $success = $this->dav->calendars()->events()->deleteOccurrence($calendar, $eventUri, $date);
        if ($success) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
        }
        return $success;
    }

    public function updateEventOccurrence(User $user, CalendarDTO $calendar, string $eventUri, \DateTime $date, EventDTO $dto): bool
    {
        $success = $this->dav->calendars()->events()->updateOccurrence($calendar, $eventUri, $date, $dto);
        if ($success) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
        }
        return $success;
    }

    public function share(CalendarDTO $calendar, User $owner, User $recipient): void
    {
        $this->dav->calendars()->sharing()->inviteUser($calendar, $owner, $recipient);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $recipient->id]);
    }

    public function revokeShare(CalendarDTO $calendar, User $recipient): void
    {
        $this->dav->calendars()->sharing()->revokeShare($calendar->calendarId, $recipient);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $recipient->id]);
    }

    public function unsubscribe(User $user, CalendarDTO $calendar): void
    {
        $this->dav->calendars()->delete($calendar);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    public function listInvites(User $user): array
    {
        return $this->dav->calendars()->sharing()->listInvites($user);
    }

    public function acceptInvite(User $user, string $token): void
    {
        $this->dav->calendars()->sharing()->acceptInvite($token);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }

    public function declineInvite(User $user, string $token): void
    {
        $this->dav->calendars()->sharing()->declineInvite($token);
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);
    }
}
