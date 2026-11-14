<?php

namespace App\Dav\Services;

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\CalendarImportDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Exceptions\ImportException;
use App\Dav\Helpers\ICalendarHelper;
use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class CalendarImport
{
    private const string CACHE_KEY_CALENDARS = 'calendars';
    private const string CACHE_KEY_EVENTS = 'calendar_events';

    private Calendars $calendars;
    private ICalendarHelper $helper;

    public function __construct(
        Calendars $calendars,
        private readonly UserCacheService $cache,
        private readonly RemoteCalDavClient $remote,
    ) {
        $this->calendars = $calendars;
        $this->helper = new ICalendarHelper();
    }

    public function start(User $user, string $url, string $username, string $secret): void
    {
        $dto = new CalendarImportDTO();
        $dto->jobId = uniqid();
        $dto->userEmail = $user->email;
        $dto->stage = 'started';
        $dto->url = rtrim($url, '/') . '/';
        $dto->username = $username;
        // The state sits in the cache for the queued import; never in plaintext.
        $dto->secret = Crypt::encryptString($secret);

        $this->saveState($user->id, $dto);
        $this->getAvailableCalendars($user, $dto);
    }

    public function selectCalendars(User $user, CalendarImportDTO $dto, array $calendars): void
    {
        $dto->selectedCalendars = [];

        foreach ($dto->calendars as $calendar) {
            foreach ($calendars as $c) {
                if ($c === $calendar['name']) {
                    $existingCalendar = $this->calendars->getByName($user, $calendar['name']);

                    if ($existingCalendar) {
                        $i = 1;

                        while ($existingCalendar) {
                            $tempName = $calendar['name'] . ' (' . $i . ')';
                            $existingCalendar = $this->calendars->getByName($user, $tempName);
                            $i++;
                        }

                        $calendar['name'] = $tempName;
                    }

                    $dto->selectedCalendars[] = $calendar;
                }
            }
        }

        $dto->calendarsCount = count($dto->selectedCalendars);
        $dto->stage = 'calendars';
        $this->saveState($user->id, $dto);
    }

    public function importCalendars(User $user, CalendarImportDTO $dto): void
    {
        $dto->stage = 'calendars';
        $this->saveState($user->id, $dto);

        foreach ($dto->selectedCalendars as $calendar) {
            $dto->currentCalendar = $calendar['name'];
            $this->saveState($user->id, $dto);

                unset($calendar['url']);
                $calendar['name'] = urldecode($calendar['name']);

            try {
                $this->calendars->create($user, CalendarDTO::fromRequest($calendar));
            } catch (\Exception $e) {
                Log::channel('dav')->warning('Calendar import skipped a calendar', [
                    'user_id'  => $user->id,
                    'calendar' => $calendar['name'],
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }

            $dto->calendarsDone++;
            $this->saveState($user->id, $dto);
        }

        $dto->stage = 'events';
        $this->saveState($user->id, $dto);
    }

    public function importEvents(User $user, CalendarImportDTO $dto): void
    {
        $dto->stage = 'events';
        $this->saveState($user->id, $dto);

        foreach ($dto->selectedCalendars as $calendar) {
            $c = $this->calendars->getByName($user, $calendar['name']);

            if (!$c) {
                continue;
            }

            $dto->currentCalendar = $calendar['name'];
            $dto->eventsCount = 0;
            $dto->eventsDone = 0;
            $this->saveState($user->id, $dto);

            $events = $this->getEvents($dto, $calendar['url'], $calendar['name']);
            $dto->eventsCount = count($events);
            $this->saveState($user->id, $dto);

            foreach ($events as $event) {
                $dto->currentCalendar = $calendar['name'];
                $this->saveState($user->id, $dto);

                try {
                    $this->calendars->events()->create($c, EventDTO::fromImport($event, $c));
                } catch (\Exception $e) {
                    Log::channel('dav')->warning('Calendar import skipped an event', [
                        'user_id'  => $user->id,
                        'calendar' => $calendar['name'],
                        'event'    => $event['uid'] ?? null,
                        'error'    => $e->getMessage(),
                    ]);
                    continue;
                }

                $dto->eventsDone++;
                $this->saveState($user->id, $dto);
            }
        }

        $dto->stage = 'finished';
        $this->saveState($user->id, $dto);
    }

    public function clearCache(User $user): void
    {
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);

        foreach ($this->calendars->list($user) as $calendar) {
            $this->cache->forgetByPrefix([self::CACHE_KEY_EVENTS, $user->id, $calendar->calendarId]);
        }
    }

    /**
     * @throws ImportException
     */
    public function getAvailableCalendars(User $user, CalendarImportDTO $dto): void
    {
        try {
            $calendars = $this->remote->discover($dto->url, $dto->username, Crypt::decryptString($dto->secret));
        } catch (BlockedUrlException) {
            throw new ImportException();
        }

        $dto->stage = 'select';
        $dto->calendars = $calendars;
        $dto->calendarsCount = count($calendars);
        $this->saveState($user->id, $dto);

        if (!$calendars) {
            throw new ImportException();
        }
    }

    private function getEvents(CalendarImportDTO $dto, string $calendarUrl, string $calendarName): array
    {
        try {
            $response = $this->remote->send(
                $dto->username,
                Crypt::decryptString($dto->secret),
                'REPORT',
                $calendarUrl,
                ['Content-Type' => 'application/xml', 'Depth' => '1'],
                $this->helper->getEntriesXml()
            );
        } catch (ExternalCalendarUnavailableException) {
            $this->logUnreadable($calendarUrl, null);

            return [];
        }

        if (!$response->successful()) {
            $this->logUnreadable($calendarUrl, $response->status());

            return [];
        }

        return $this->helper->parseEntriesFromXml($response->body(), $calendarName);
    }

    private function logUnreadable(string $calendarUrl, ?int $status): void
    {
        Log::channel('dav')->warning('Calendar import could not read a calendar', [
            'host'   => parse_url($calendarUrl, PHP_URL_HOST),
            'status' => $status,
        ]);
    }

    private function saveState(string $userId, CalendarImportDTO $dto): void
    {
        Cache::set('calendar-import-' . $userId, $dto, now()->addMinutes(60));
    }

    public function getState(string $userId): ?CalendarImportDTO
    {
        return Cache::get('calendar-import-' . $userId);
    }
}
