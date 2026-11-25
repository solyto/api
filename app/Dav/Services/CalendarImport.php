<?php

namespace App\Dav\Services;

use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Calendars\Services\CalendarService;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\CalendarImportDTO;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Exceptions\ImportConflictException;
use App\Dav\Exceptions\ImportException;
use App\Dav\Exceptions\ImportFileException;
use App\Dav\Exceptions\ImportTargetForbiddenException;
use App\Dav\Helpers\ICalendarHelper;
use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Services\UserCacheService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Node;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

class CalendarImport
{
    private const string CACHE_KEY_CALENDARS = 'calendars_v3';

    // A whole calendar comes in one REPORT, so the external calendar limits (5 s, 10 MB) are too tight.
    // 30 s matches the import before 1.1.3; the cap keeps a response within the queue worker's memory.
    private const int EVENTS_TIMEOUT = 30;
    private const int EVENTS_MAX_BYTES = 50 * 1024 * 1024;

    public const int MAX_FILE_OBJECTS = 5000;
    // Every stored event carries its own copy of the time zones it uses (a CalDAV object must be complete), so a
    // small file with one huge VTIMEZONE used by every event would write gigabytes. 50 MB is 5000 events with a
    // 10 KB zone each; real zones are a few KB.
    private const int MAX_TIMEZONE_COPY_BYTES = 50 * 1024 * 1024;

    private const string LOCK_KEY = 'calendar-import-lock-';
    private const int LOCK_TTL = 10;
    // A running import that has saved no progress for this long has lost its worker. Both imports save after
    // every event; the longest gap is one CalDAV REPORT (EVENTS_TIMEOUT), so only a job still waiting in the
    // queue can be mistaken for a dead one.
    private const int STALE_AFTER = 300;
    private const string FILE_DISK = 'local';
    private const string FILE_DIRECTORY = 'calendar-imports';
    private const string UNREADABLE_FILE_MESSAGE = 'This file could not be read as an iCalendar file.';

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

    public function start(User $user, string $url, string $username, #[\SensitiveParameter] string $secret): void
    {
        $dto = new CalendarImportDTO();
        $dto->jobId = uniqid();
        $dto->userEmail = $user->email;
        $dto->stage = 'started';
        $dto->url = rtrim($url, '/') . '/';
        $dto->username = $username;
        // The state sits in the cache for the queued import; never in plaintext.
        $dto->secret = Crypt::encryptString($secret);

        $this->claim($user->id, $dto);

        try {
            $this->getAvailableCalendars($user, $dto);
        } catch (\Throwable $e) {
            $dto->stage = 'failed';
            $this->saveState($user->id, $dto);

            throw $e;
        }
    }

    /**
     * @throws ImportException
     * @throws ImportConflictException
     */
    public function selectCalendars(User $user, CalendarImportDTO $dto, array $calendars): void
    {
        if ($dto->source !== 'caldav') {
            throw new ImportException('Import state not found');
        }

        if ($this->isRunning($dto)) {
            throw new ImportConflictException('A calendar import is already running.');
        }

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
                // calendarinstances.displayname is VARCHAR(100) on pgsql; a longer remote name would skip the calendar.
                $calendar['name'] = mb_substr(urldecode($calendar['name']), 0, CalendarService::MAX_NAME_LENGTH);

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

        $doneTotal = 0;
        $skippedTotal = 0;

        foreach ($dto->selectedCalendars as $calendar) {
            $c = $this->calendars->getByName($user, $calendar['name']);

            if (!$c) {
                continue;
            }

            $dto->currentCalendar = $calendar['name'];
            $dto->eventsCount = 0;
            $dto->eventsDone = 0;
            $dto->eventsSkipped = 0;
            $this->saveState($user->id, $dto);

            $objects = $this->getEvents($dto, $calendar['url']);
            $dto->eventsCount = count($objects);
            $this->saveState($user->id, $dto);

            foreach ($objects as $group) {
                if ($group && $this->storeGroup($user, $c, $group)) {
                    $dto->eventsDone++;
                    $doneTotal++;
                } else {
                    $dto->eventsSkipped++;
                    $skippedTotal++;
                }

                $this->saveState($user->id, $dto);
            }
        }

        $dto->eventsCount = $doneTotal + $skippedTotal;
        $dto->eventsDone = $doneTotal;
        $dto->eventsSkipped = $skippedTotal;
        $dto->stage = 'finished';
        $this->saveState($user->id, $dto);
    }

    /**
     * @throws ImportFileException
     * @throws ImportConflictException
     * @throws ImportTargetForbiddenException
     */
    public function startFile(User $user, UploadedFile $file, ?CalendarDTO $target, ?string $name, ?string $color): CalendarImportDTO
    {
        if ($target && ($target->isShared || $target->isExternal)) {
            throw new ImportTargetForbiddenException('You can only import into your own calendars.');
        }

        $content = (string) $file->get();
        [$vCal, $groups] = $this->splitFile($content);
        $fileCalendarName = trim((string) $vCal->{'X-WR-CALNAME'});
        $vCal->destroy();

        $dto = new CalendarImportDTO();
        $dto->jobId = (string) Str::uuid();
        $dto->userEmail = $user->email;
        $dto->source = 'file';
        $dto->eventsCount = count($groups);

        if ($target) {
            $dto->targetInstanceId = $target->instanceId;
            $dto->currentCalendar = $target->displayName;
            $dto->stage = 'events';
        } else {
            $calendarName = $this->uniqueName($user, $name ?: $fileCalendarName ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Imported');
            $dto->selectedCalendars = [['name' => $calendarName, 'color' => $color]];
            $dto->currentCalendar = $calendarName;
            $dto->calendarsCount = 1;
            $dto->stage = 'calendars';
        }

        Storage::disk(self::FILE_DISK)->put($this->filePath($dto->jobId), $content);

        try {
            $this->claim($user->id, $dto);
        } catch (ImportConflictException $e) {
            $this->deleteFile($dto->jobId);

            throw $e;
        }

        return $dto;
    }

    // A job that waited in the queue past STALE_AFTER may find a newer import's state; the job id keeps it off.
    /**
     * @throws ImportFileException
     */
    public function importFile(string $userId, string $jobId): void
    {
        $user = User::find($userId);
        $dto = $this->getState($userId);

        if (!$user || !$dto || $dto->jobId !== $jobId || !in_array($dto->stage, ['calendars', 'events'], true)) {
            $this->deleteFile($jobId);

            return;
        }

        $vCal = null;

        try {
            $content = Storage::disk(self::FILE_DISK)->get($this->filePath($jobId));
            [$vCal, $groups] = $this->splitFile($content ?? throw new ImportFileException(self::UNREADABLE_FILE_MESSAGE));
            unset($content);

            // The name was free at upload; a calendar created since then must not receive the events.
            if ($dto->targetInstanceId === null) {
                $dto->selectedCalendars[0]['name'] = $this->uniqueName($user, $dto->selectedCalendars[0]['name']);
                $dto->currentCalendar = $dto->selectedCalendars[0]['name'];
                $dto->targetInstanceId = $this->calendars->create($user, CalendarDTO::fromRequest($dto->selectedCalendars[0]))->instanceId;
                $dto->calendarsDone = 1;
            }

            $calendar = $this->calendars->get($user, $dto->targetInstanceId);

            if (!$calendar || $calendar->isShared || $calendar->isExternal) {
                throw new ImportFileException('The calendar to import into is not available.');
            }

            $dto->stage = 'events';
            $this->saveState($userId, $dto);
            $storedUids = array_fill_keys($this->calendars->events()->uids($calendar), true);

            foreach ($groups as $uid => $group) {
                if (isset($storedUids[$uid])) {
                    $dto->eventsSkipped++;
                } else if ($this->storeGroup($user, $calendar, $group)) {
                    $dto->eventsDone++;
                } else {
                    $dto->eventsSkipped++;
                }

                unset($groups[$uid]);
                $this->saveState($userId, $dto);
            }

            $dto->stage = 'finished';
            $this->saveState($userId, $dto);

            if ($calendar->solytoShared) {
                InvalidateSharedCalendarCache::dispatch($calendar->calendarId);
            }
        } finally {
            $vCal?->destroy();
            $this->deleteFile($jobId);
            $this->clearCache($user);
        }
    }

    public function fail(string $userId, string $jobId): void
    {
        $this->deleteFile($jobId);
        $dto = $this->getState($userId);

        if (!$dto || $dto->jobId !== $jobId) {
            return;
        }

        $dto->stage = 'failed';
        $this->saveState($userId, $dto);

        if ($user = User::find($userId)) {
            $this->clearCache($user);
        }
    }

    public function clearCache(User $user): void
    {
        $this->cache->forget([self::CACHE_KEY_CALENDARS, $user->id]);

        foreach ($this->calendars->list($user) as $calendar) {
            $this->cache->flushTags([CalendarService::eventCacheTag($user->id, $calendar->calendarId)]);
        }
    }

    /**
     * @throws ImportException
     */
    public function getAvailableCalendars(User $user, CalendarImportDTO $dto): void
    {
        try {
            $calendars = $this->remote->discover($dto->url, $dto->username, Crypt::decryptString($dto->secret));
        } catch (BlockedUrlException|ExternalCalendarUnavailableException) {
            throw $this->unreachable($dto);
        }

        if (!$calendars) {
            throw $this->unreachable($dto);
        }

        $dto->stage = 'select';
        $dto->calendars = $calendars;
        $dto->calendarsCount = count($calendars);
        $this->saveState($user->id, $dto);
    }

    // An unreachable server or wrong credentials is user input, not an app error: log it, don't report it.
    private function unreachable(CalendarImportDTO $dto): ImportException
    {
        Log::channel('dav')->warning('Calendar import could not reach the server', [
            'host' => parse_url($dto->url, PHP_URL_HOST),
        ]);

        return new ImportException('Could not grab calendars');
    }

    // One entry per object stored on the remote, split per UID; null stands for an entry that can't be read, so it
    // is counted as skipped.
    /** @return array<int, ?array{components: array<int, Component>, timezones: array<int, Component>}> */
    private function getEvents(CalendarImportDTO $dto, string $calendarUrl): array
    {
        try {
            $response = $this->remote->send(
                $dto->username,
                Crypt::decryptString($dto->secret),
                'REPORT',
                $calendarUrl,
                headers: ['Content-Type' => 'application/xml', 'Depth' => '1'],
                body: $this->helper->getEntriesXml(),
                timeout: self::EVENTS_TIMEOUT,
                maxBytes: self::EVENTS_MAX_BYTES
            );
        } catch (ExternalCalendarUnavailableException) {
            $this->logUnreadable($calendarUrl, null);

            return [];
        }

        if (!$response->successful()) {
            $this->logUnreadable($calendarUrl, $response->status());

            return [];
        }

        $objects = [];

        foreach ($this->helper->calendarDataFromXml($response->body()) as $data) {
            try {
                $vCal = Reader::read($data, Reader::OPTION_FORGIVING);
            } catch (ParseException|InvalidDataException) {
                $vCal = null;
            }

            array_push($objects, ...($vCal instanceof VCalendar ? array_values($this->splitCalendar($vCal)) : [null]));
        }

        return $objects;
    }

    private function logUnreadable(string $calendarUrl, ?int $status): void
    {
        Log::channel('dav')->warning('Calendar import could not read a calendar', [
            'host'   => parse_url($calendarUrl, PHP_URL_HOST),
            'status' => $status,
        ]);
    }

    // The groups point into the returned calendar; the caller destroys it once it is done with them.
    /**
     * @return array{0: VCalendar, 1: array<string, array{components: array<int, Component>, timezones: array<int, Component>}>}
     *
     * @throws ImportFileException
     */
    private function splitFile(string $content): array
    {
        try {
            $vCal = Reader::read($content, Reader::OPTION_FORGIVING);
        } catch (ParseException|InvalidDataException) {
            throw new ImportFileException(self::UNREADABLE_FILE_MESSAGE);
        }

        if (!$vCal instanceof VCalendar) {
            throw new ImportFileException(self::UNREADABLE_FILE_MESSAGE);
        }

        try {
            $groups = $this->splitCalendar($vCal);

            if (!isset($vCal->VEVENT)) {
                throw new ImportFileException('This file contains no events.');
            }

            if ($this->timezoneCopyBytes($groups) > self::MAX_TIMEZONE_COPY_BYTES) {
                throw new ImportFileException('The time zones in this file are too large to import.');
            }
        } catch (ImportFileException $e) {
            $vCal->destroy();

            throw $e;
        }

        return [$vCal, $groups];
    }

    // Same UID grouping as Sabre\VObject\Splitter\ICalendar, but a missing UID gets a UUID: the splitter's
    // sha1(microtime()) can repeat within one file and merge unrelated events into one object. Calendar-level
    // properties such as METHOD stay behind; a CalDAV object must not carry them.
    // A group only points into the parsed calendar; buildObject() copies one group at a time, so a timezone that
    // many events use is not held once per event.
    /**
     * @return array<string, array{components: array<int, Component>, timezones: array<int, Component>}>
     *
     * @throws ImportFileException
     */
    private function splitCalendar(VCalendar $vCal): array
    {
        $timezones = [];
        $groups = [];
        $usedTimezones = [];
        $count = 0;

        foreach ($vCal->getComponents() as $component) {
            if ($component->name === 'VTIMEZONE') {
                $timezones[(string) $component->TZID] = $component;
                continue;
            }

            if (++$count > self::MAX_FILE_OBJECTS) {
                throw new ImportFileException(sprintf('This file contains more than %d events.', self::MAX_FILE_OBJECTS));
            }

            $uid = (string) $component->UID;

            if ($uid === '') {
                $uid = (string) Str::uuid();
                $component->UID = $uid;
            }

            $groups[$uid]['components'][] = $component;

            $usedTimezones[$uid] ??= [];
            $this->collectTimezones($component, $usedTimezones[$uid]);
        }

        foreach ($groups as $uid => $group) {
            $groups[$uid]['timezones'] = array_values(array_intersect_key($timezones, $usedTimezones[$uid]));
        }

        return $groups;
    }

    /** @param array<string, array{components: array<int, Component>, timezones: array<int, Component>}> $groups */
    private function timezoneCopyBytes(array $groups): int
    {
        $sizes = [];
        $total = 0;

        foreach ($groups as $group) {
            foreach ($group['timezones'] as $timezone) {
                $total += $sizes[spl_object_id($timezone)] ??= strlen($timezone->serialize());
            }
        }

        return $total;
    }

    /** @param array{components: array<int, Component>, timezones: array<int, Component>} $group */
    private function buildObject(array $group): VCalendar
    {
        $object = new VCalendar();

        foreach ([...$group['components'], ...$group['timezones']] as $component) {
            $object->add(clone $component);
        }

        return $object;
    }

    /** @param array<string, true> $tzids */
    private function collectTimezones(Node $node, array &$tzids): void
    {
        foreach ($node->children() as $child) {
            if ($child instanceof Property) {
                if (isset($child['TZID'])) {
                    $tzids[(string) $child['TZID']] = true;
                }
            } else if ($child instanceof Component) {
                $this->collectTimezones($child, $tzids);
            }
        }
    }

    /** @param array{components: array<int, Component>, timezones: array<int, Component>} $group */
    private function storeGroup(User $user, CalendarDTO $calendar, array $group): bool
    {
        $object = $this->buildObject($group);

        try {
            return $this->storeObject($user, $calendar, $object);
        } finally {
            $object->destroy();
        }
    }

    // Checked like a CalDAV client's PUT (Sabre\CalDAV\Plugin::validateICalendar), so the import stores
    // nothing a sync client could not have written.
    private function storeObject(User $user, CalendarDTO $calendar, VCalendar $object): bool
    {
        if (!isset($object->VEVENT)) {
            return false;
        }

        $uid = (string) $object->VEVENT->UID;

        foreach ($object->VEVENT as $event) {
            if (!isset($event->DTSTART)) {
                $this->logSkippedEvent($user, $uid, 'No DTSTART');

                return false;
            }
        }

        foreach ($object->validate(Node::PROFILE_CALDAV | Node::REPAIR) as $message) {
            if ($message['level'] >= 3) {
                $this->logSkippedEvent($user, $uid, $message['message']);

                return false;
            }
        }

        // The backend raises whatever sabre or the database throws for one bad object; it must not end the import.
        try {
            return $this->calendars->events()->import($calendar, $object->serialize()) !== null;
        } catch (\Exception $e) {
            $this->logSkippedEvent($user, $uid, $e->getMessage());

            return false;
        }
    }

    private function logSkippedEvent(User $user, string $uid, string $error): void
    {
        Log::channel('dav')->warning('Calendar file import skipped an event', [
            'user_id' => $user->id,
            'event'   => $uid,
            'error'   => $error,
        ]);
    }

    private function uniqueName(User $user, string $name): string
    {
        $name = mb_substr($name, 0, CalendarService::MAX_NAME_LENGTH);
        $candidate = $name;
        $i = 1;

        while ($this->calendars->getByName($user, $candidate)) {
            $suffix = ' (' . $i++ . ')';
            $candidate = mb_substr($name, 0, CalendarService::MAX_NAME_LENGTH - mb_strlen($suffix)) . $suffix;
        }

        return $candidate;
    }

    private function filePath(string $jobId): string
    {
        return self::FILE_DIRECTORY . '/' . $jobId . '.ics';
    }

    private function deleteFile(string $jobId): void
    {
        Storage::disk(self::FILE_DISK)->delete($this->filePath($jobId));
    }

    private function isRunning(?CalendarImportDTO $dto): bool
    {
        // A state saved before updatedAt existed is from before a deploy; its worker is gone.
        return in_array($dto?->stage, ['calendars', 'events'], true)
            && $dto->updatedAt !== null && $dto->updatedAt > now()->getTimestamp() - self::STALE_AFTER;
    }

    // The lock keeps two requests from both passing the running check before either saves.
    /**
     * @throws ImportConflictException
     */
    private function claim(string $userId, CalendarImportDTO $dto): void
    {
        $lock = Cache::lock(self::LOCK_KEY . $userId, self::LOCK_TTL);

        if (!$lock->get()) {
            throw new ImportConflictException('A calendar import is already running.');
        }

        try {
            if ($this->isRunning($this->getState($userId))) {
                throw new ImportConflictException('A calendar import is already running.');
            }

            $this->saveState($userId, $dto);
        } finally {
            $lock->release();
        }
    }

    private function saveState(string $userId, CalendarImportDTO $dto): void
    {
        $dto->updatedAt = now()->getTimestamp();
        Cache::set('calendar-import-' . $userId, $dto, now()->addMinutes(60));
    }

    // A session started before the secret was encrypted holds it in plaintext; it is dropped and reads as expired.
    public function getState(string $userId): ?CalendarImportDTO
    {
        $dto = Cache::get('calendar-import-' . $userId);

        if ($dto === null) {
            return null;
        }

        try {
            if ($dto->secret !== null) {
                Crypt::decryptString($dto->secret);
            }
        } catch (DecryptException) {
            Cache::forget('calendar-import-' . $userId);

            return null;
        }

        return $dto;
    }
}
