<?php

namespace App\Dav\Services;

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\CalendarImportDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Exceptions\ImportException;
use App\Dav\Helpers\ICalendarHelper;
use App\Shared\Services\UserCacheService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CalendarImport
{
    private const string CACHE_KEY_CALENDARS = 'calendars';
    private const string CACHE_KEY_EVENTS = 'calendar_events';

    private Calendars $calendars;
    private ICalendarHelper $helper;

    public function __construct(Calendars $calendars, private readonly UserCacheService $cache)
    {
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
        $dto->secret = $secret;

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

    public function getAvailableCalendars(User $user, CalendarImportDTO $dto): void
    {
        $homeSet = $this->discoverCalendarHome($dto);
        $calendars = $this->getCalendars($dto, $homeSet);

        $dto->stage = 'select';
        $dto->calendars = $calendars;
        $dto->calendarsCount = count($calendars);
        $this->saveState($user->id, $dto);

        if (!$calendars) {
            throw new ImportException();
        }
    }

    private function discoverCalendarHome(CalendarImportDTO $dto): string
    {
        $response = $this->getBasicRequest($dto)->send(
            'PROPFIND',
            $dto->url . 'principals/users/' . $dto->username . '/',
            ['body' => $this->helper->getCalendarHomeDiscoveryXml()]
        );


        if (!$response->successful()) {
            throw new ImportException();
        }

        $homes = $this->helper->parseCalendarHomeFromXml($response->body(), $dto->url);
        return $homes[0]['url'] ?? throw new ImportException();
    }

    private function getCalendars(CalendarImportDTO $dto, string $homeSet): array
    {
        $response = $this->getBasicRequest($dto)->send(
            'PROPFIND',
            $homeSet,
            ['body' => ICalendarHelper::getCalendarsXml()]
        );

        if (!$response->successful()) {
            return [];
        }

        return $this->helper->parseCalendarsFromXml($response->body(), $homeSet);
    }

    private function getEvents(CalendarImportDTO $dto, string $calendarUrl, string $calendarName): array
    {
        $response = $this->getBasicRequest($dto)->send(
            'REPORT',
            $calendarUrl,
            ['body' => $this->helper->getEntriesXml()]
        );

        if (!$response->successful()) {
            return [];
        }

        return $this->helper->parseEntriesFromXml($response->body(), $calendarName);
    }

    private function getBasicRequest(CalendarImportDTO $dto): PendingRequest
    {
        return Http::withBasicAuth($dto->username, $dto->secret)
                   ->withHeaders([
                       'Content-Type' => 'application/xml',
                       'Depth' => '1',
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
