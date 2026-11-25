<?php

namespace App\Dav\Backend;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Services\CalendarService;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Exceptions\UnreadableCalendarObjectException;
use App\Dav\Helpers\DavHelper;
use App\Dav\Helpers\VEventHelper;
use App\Dav\Services\RemoteCalDavClient;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sabre\CalDAV\Backend\PDO as SabreBackend;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Xml\Element\Sharee;
use Sabre\VObject\Reader;

// External calendars are native calendar rows without objects: object-level calls on them go to the user's own
// CalDAV server through RemoteCalDavClient, calendar-level calls stay local. Native calendars take the parent's paths.
class AppCalendarsPDO extends SabreBackend
{
    private const string SYNC_TOKEN_MARKER = 'ext-';
    // Also forgotten by CalendarService::forgetRemoteState().
    private const string CACHE_KEY_STATE = 'external_calendar_state';
    // Shared with CalendarService: a remote that failed is skipped by both for the unavailable TTL.
    private const string CACHE_KEY_UNAVAILABLE = 'external_calendar_unavailable';
    // Sabre's MAX_DATE (2038-01-01 UTC): series running past it are stored with lastoccurence capped here or just after.
    private const int LAST_OCCURRENCE_CAP = 2145916800;
    // calendarinstances.uri is VARCHAR(200); the rest is for uniqueUri()'s "-n" suffix.
    private const int MAX_URI_BASE_LENGTH = 190;
    // Sabre stores floating all-day DATE values as UTC, up to 14 h off the user's wall clock.
    private const int FLOATING_DATE_PADDING = 86400;

    /** @var array<int, ExternalCalendar|false> pgsql calendarid => link, false for native */
    private array $external = [];

    /** @var array<string, true> */
    private array $loadedPrincipals = [];

    // The If-Match of the current DAV request, passed on to remote writes.
    private ?string $ifMatch = null;

    // Who sent the current DAV request; null outside DAV (API, jobs), which check ownership themselves.
    private ?string $requestPrincipal = null;

    // Objects a calendar-query already read with their data, handed to the multiget sabre runs right after it
    // so they are not fetched twice.
    /** @var array<int, array<string, array<string, mixed>>> */
    private array $prefetched = [];

    // Calendars shared with the principal reach DAV clients only once the invite is accepted, as in the app.
    // listCalendarsCustom() reads the parent directly, so the app still sees pending invites.
    public function getCalendarsForUser($principalUri)
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access > ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER . ' AND share_invitestatus <> ?'
        );
        $stmt->execute([$principalUri, \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED]);
        $notAcceptedInstanceIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $calendars = array_values(array_filter(
            parent::getCalendarsForUser($principalUri),
            fn (array $calendar) => !in_array($calendar['id'][1], $notAcceptedInstanceIds, true)
        ));

        $this->loadExternal($principalUri, $calendars);

        return array_map(fn (array $calendar) => $this->withRemoteState($calendar), $calendars);
    }

    public function setRequestPreconditions(?string $ifMatch): void
    {
        $this->ifMatch = $ifMatch !== '' ? $ifMatch : null;
    }

    public function setRequestPrincipal(?string $principalUri): void
    {
        $this->requestPrincipal = $principalUri;
    }

    public function externalCalendar(int $calendarId): ?ExternalCalendar
    {
        if (!array_key_exists($calendarId, $this->external)) {
            $this->external[$calendarId] = ExternalCalendar::with('account')->where('calendar_id', $calendarId)->first() ?? false;
        }

        return $this->external[$calendarId] ?: null;
    }

    // After a calendar was linked or unlinked, so the next call looks it up again.
    public function forgetExternal(int $calendarId): void
    {
        unset($this->external[$calendarId], $this->prefetched[$calendarId]);
        $this->loadedPrincipals = [];
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::getCalendarObject($calendarId, $objectUri);
        }

        if (isset($this->prefetched[$link->calendar_id][$objectUri])) {
            $object = $this->prefetched[$link->calendar_id][$objectUri];
            unset($this->prefetched[$link->calendar_id][$objectUri]);

            return $object;
        }

        $object = $this->remoteFor($link)->getObject($link, $objectUri);

        return $object ? $this->objectRow($link, $object) : null;
    }

    public function getCalendarObjects($calendarId)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::getCalendarObjects($calendarId);
        }

        return array_map(fn (array $object) => $this->objectRow($link, $object), $this->remoteFor($link)->listObjects($link));
    }

    public function getMultipleCalendarObjects($calendarId, array $uris)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::getMultipleCalendarObjects($calendarId, $uris);
        }

        $objects = [];
        $missing = [];
        foreach ($uris as $uri) {
            if (isset($this->prefetched[$link->calendar_id][$uri])) {
                $objects[] = $this->prefetched[$link->calendar_id][$uri];
                unset($this->prefetched[$link->calendar_id][$uri]);
            } else {
                $missing[] = $uri;
            }
        }

        foreach ($this->remoteFor($link)->multiget($link, $missing) as $object) {
            $objects[] = $this->objectRow($link, $object);
        }

        return $objects;
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::createCalendarObject($calendarId, $objectUri, $calendarData);
        }

        return $this->putRemote($link, $objectUri, $calendarData, true, null);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::updateCalendarObject($calendarId, $objectUri, $calendarData);
        }

        return $this->putRemote($link, $objectUri, $calendarData, false, $this->ifMatch);
    }

    // Sabre takes the occurrence bounds from the first VEVENT, so a client that writes an override before its
    // master would hide the series from time-range queries.
    protected function getDenormalizedData($calendarData)
    {
        return array_merge(parent::getDenormalizedData($calendarData), $this->masterOccurrenceBounds($calendarData) ?? []);
    }

    // Null unless the object's first component is an override written before its master.
    /** @return array{firstOccurence: int, lastOccurence: int}|null */
    public function masterOccurrenceBounds(string $calendarData): ?array
    {
        $vCal = Reader::read($calendarData);

        try {
            $components = array_values(array_filter($vCal->getComponents(), fn ($c) => $c->name !== 'VTIMEZONE'));
            $first = $components[0] ?? null;
            if (!$first || $first->name !== 'VEVENT' || !isset($first->{'RECURRENCE-ID'})) {
                return null;
            }

            $events = $vCal->select('VEVENT');
            $master = collect($events)->first(fn ($e) => !isset($e->{'RECURRENCE-ID'}) && (string) $e->UID === (string) $first->UID);
            if (!$master) {
                return null;
            }

            $vCal->remove('VEVENT');
            $vCal->add($master);
            foreach ($events as $event) {
                if ($event !== $master) {
                    $vCal->add($event);
                }
            }

            $data = parent::getDenormalizedData($vCal->serialize());

            return ['firstOccurence' => (int) $data['firstOccurence'], 'lastOccurence' => (int) $data['lastOccurence']];
        } finally {
            $vCal->destroy();
        }
    }

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            parent::deleteCalendarObject($calendarId, $objectUri);

            return;
        }

        $this->deleteRemote($link, $objectUri, $this->ifMatch);
    }

    // The remote narrows by component and time range; the exact filter runs here on the returned data,
    // as sabre does for its own post-filter.
    public function calendarQuery($calendarId, array $filters)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::calendarQuery($calendarId, $filters);
        }

        $component = null;
        $start = null;
        $end = null;
        $first = $filters['comp-filters'][0] ?? null;
        if ($first && !$first['is-not-defined']) {
            $component = $first['name'];
            $start = $first['time-range']['start'] ?? null;
            $end = $first['time-range']['end'] ?? null;
        }

        $uris = [];
        foreach ($this->remoteFor($link)->query($link, $component, $start, $end) as $object) {
            $row = $this->objectRow($link, $object);

            // Remote objects were never validated by sabre: one that cannot be read is skipped, not allowed to fail the query.
            try {
                $matches = $this->validateFilterForObject($row, $filters);
            } catch (\Throwable $e) {
                $this->logSkippedObject($link->calendar_id, $row['uri'], $e);
                continue;
            }

            if ($matches) {
                $uris[] = $row['uri'];
                $this->prefetched[$link->calendar_id][$row['uri']] = $row;
            }
        }

        return $uris;
    }

    // The remote sync token travels behind sabre's prefix as "ext-" + base64url, so it survives as a URI suffix
    // whatever it looks like. A token that is not ours or that the remote refuses makes the client resync from scratch.
    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit);
        }

        $remoteToken = '';
        if ($syncToken !== null && $syncToken !== '') {
            $remoteToken = $this->decodeSyncToken((string) $syncToken);
            if ($remoteToken === null) {
                return null;
            }
        }

        $changes = $this->remoteFor($link)->syncCollection($link, $remoteToken);
        if ($changes === null) {
            return null;
        }

        return [
            'syncToken' => $this->encodeSyncToken($changes['sync_token']),
            'added' => [],
            'modified' => $changes['modified'],
            'deleted' => $changes['deleted'],
            'result_truncated' => $changes['truncated'],
        ];
    }

    public function deleteCalendar($calendarId)
    {
        parent::deleteCalendar($calendarId);

        $link = $this->linkFor($calendarId);
        if ($link) {
            app(UserCacheService::class)->forget($this->stateKey($link));
            $link->delete();
            $this->forgetExternal($link->calendar_id);
        }
    }

    public function listCalendarsCustom(string $principalUri): array
    {
        $entries = parent::getCalendarsForUser($principalUri);
        $this->loadExternal($principalUri, $entries);

        $stmt = $this->pdo->prepare(
            'SELECT ci.share_invitestatus, ci.share_href, o.principaluri AS owner_principaluri
             FROM ' . $this->calendarInstancesTableName . ' ci
             LEFT JOIN ' . $this->calendarInstancesTableName . ' o ON o.calendarid = ci.calendarid AND o.access = ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER . '
             WHERE ci.id = ?'
        );

        // A separate query: parent::getCalendarsForUser() names its own columns, so solyto_shared can't be added there.
        $flagged = $this->pdo->prepare(
            'SELECT id FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access = ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER . ' AND solyto_shared'
        );
        $flagged->execute([$principalUri]);
        $sharedOwnInstances = array_map('intval', $flagged->fetchAll(\PDO::FETCH_COLUMN));

        $calendars = [];

        foreach ($entries as $entry) {
            $entry['solyto-shared'] = in_array($entry['id'][1], $sharedOwnInstances, true);

            if ((int) ($entry['share-access'] ?? 0) > 1) {
                $stmt->execute([$entry['id'][1]]);
                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $entry['share-invitestatus'] = (int) $row['share_invitestatus'];
                    $entry['share-href']         = $row['share_href'];
                    $entry['owner-name'] = User::where('email', $this->principalEmail($row['owner_principaluri']))->value('name');
                }
            }
            $entry['is-external'] = $this->isExternal($entry['id']);
            $calendars[] = CalendarDTO::fromSabre($entry);
        }

        return $calendars;
    }

    public function getCalendarByIdCustom(string $principalUri, int $instanceId): ?CalendarDTO
    {
        $fields = array_values($this->propertyMap);
        $fields[] = 'calendarid';
        $fields[] = 'uri';
        $fields[] = 'synctoken';
        $fields[] = 'components';
        $fields[] = 'principaluri';
        $fields[] = 'transparent';
        $fields[] = 'access';
        $fields[] = 'solyto_shared';

        $fields = implode(', ', $fields);
        $stmt = $this->pdo->prepare(<<<SQL
SELECT {$this->calendarInstancesTableName}.id as id, $fields FROM {$this->calendarInstancesTableName}
    LEFT JOIN {$this->calendarTableName} ON
        {$this->calendarInstancesTableName}.calendarid = {$this->calendarTableName}.id
WHERE principaluri = ? AND {$this->calendarInstancesTableName}.id = ? ORDER BY calendarorder ASC
SQL
        );
        $stmt->execute([$principalUri, $instanceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $components = [];
        if ($row['components']) {
            $components = explode(',', $row['components']);
        }

        $calendar = [
            'id' => [(int) $row['calendarid'], (int) $row['id']],
            'uri' => $row['uri'],
            'principaluri' => $row['principaluri'],
            '{'.Plugin::NS_CALENDARSERVER.'}getctag' => 'http://sabre.io/ns/sync/'.($row['synctoken'] ? $row['synctoken'] : '0'),
            '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ? $row['synctoken'] : '0',
            '{'.Plugin::NS_CALDAV.'}supported-calendar-component-set' => new SupportedCalendarComponentSet($components),
            '{'.Plugin::NS_CALDAV.'}schedule-calendar-transp' => new ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
            'share-resource-uri' => '/ns/share/'.$row['calendarid'],
        ];

        $calendar['share-access'] = (int) $row['access'];
        if ($row['access'] > 1) {
            $calendar['read-only'] = \Sabre\DAV\Sharing\Plugin::ACCESS_READ === (int) $row['access'];
        }
        $calendar['solyto-shared'] = (bool) $row['solyto_shared'];

        foreach ($this->propertyMap as $xmlName => $dbName) {
            $calendar[$xmlName] = $row[$dbName];
        }

        $calendar['is-external'] = $this->isExternal($calendar['id']);

        return CalendarDTO::fromSabre($calendar);
    }

    public function getCalendarByNameCustom(string $principalUri, string $displayName): ?CalendarDTO
    {
        $fields = array_values($this->propertyMap);
        $fields[] = 'calendarid';
        $fields[] = 'uri';
        $fields[] = 'synctoken';
        $fields[] = 'components';
        $fields[] = 'principaluri';
        $fields[] = 'transparent';
        $fields[] = 'access';
        $fields[] = 'solyto_shared';

        $fields = implode(', ', $fields);
        $stmt = $this->pdo->prepare(<<<SQL
SELECT {$this->calendarInstancesTableName}.id as id, $fields FROM {$this->calendarInstancesTableName}
    LEFT JOIN {$this->calendarTableName} ON
        {$this->calendarInstancesTableName}.calendarid = {$this->calendarTableName}.id
WHERE principaluri = ? AND displayname = ? ORDER BY calendarorder ASC
SQL
        );
        $stmt->execute([$principalUri, $displayName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $components = [];
        if ($row['components']) {
            $components = explode(',', $row['components']);
        }

        $calendar = [
            'id' => [(int) $row['calendarid'], (int) $row['id']],
            'uri' => $row['uri'],
            'principaluri' => $row['principaluri'],
            '{'.Plugin::NS_CALENDARSERVER.'}getctag' => 'http://sabre.io/ns/sync/'.($row['synctoken'] ? $row['synctoken'] : '0'),
            '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ? $row['synctoken'] : '0',
            '{'.Plugin::NS_CALDAV.'}supported-calendar-component-set' => new SupportedCalendarComponentSet($components),
            '{'.Plugin::NS_CALDAV.'}schedule-calendar-transp' => new ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
            'share-resource-uri' => '/ns/share/'.$row['calendarid'],
        ];

        $calendar['share-access'] = (int) $row['access'];
        if ($row['access'] > 1) {
            $calendar['read-only'] = \Sabre\DAV\Sharing\Plugin::ACCESS_READ === (int) $row['access'];
        }
        $calendar['solyto-shared'] = (bool) $row['solyto_shared'];

        foreach ($this->propertyMap as $xmlName => $dbName) {
            $calendar[$xmlName] = $row[$dbName];
        }

        $calendar['is-external'] = $this->isExternal($calendar['id']);

        return CalendarDTO::fromSabre($calendar);
    }

    /** @return array{0: string, 1: string} [calendarId, instanceId], as sabre returns them */
    public function createCalendarCustom(string $principalUri, CalendarDTO $dto): array
    {
        $calendar = [
            '{DAV:}displayname'  => $dto->displayName,
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => $dto->description,
            'components'   => 'VEVENT',
            '{http://apple.com/ns/ical/}calendar-order'=> 0,
            '{http://apple.com/ns/ical/}calendar-color'=> $dto->color,
            'transparent'  => 0,
            'timezone' => $dto->timezone,
        ];

        return parent::createCalendar($principalUri, $this->uniqueUri($principalUri, $dto->displayName), $calendar);
    }

    // (principaluri, uri) is unique, but different display names can slug alike ("Work" and "work!") or to nothing
    // (emoji-only names). Transliteration can make the slug longer than the name (one Cyrillic letter can become
    // three), so it is cut to leave room for a "-n" suffix in uri's VARCHAR(200).
    private function uniqueUri(string $principalUri, string $displayName): string
    {
        $base = rtrim(substr(Str::slug($displayName), 0, self::MAX_URI_BASE_LENGTH), '-');
        if ($base === '') {
            return Str::uuid()->toString();
        }

        $candidate = $base;
        $i = 1;
        while ($this->uriTaken($principalUri, $candidate)) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    private function uriTaken(string $principalUri, string $uri): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND uri = ?'
        );
        $stmt->execute([$principalUri, $uri]);

        return $stmt->fetchColumn() !== false;
    }

    public function updateCalendarCustom(CalendarDTO $dto): void
    {
        $propPatch = new \Sabre\DAV\PropPatch([
            '{http://apple.com/ns/ical/}calendar-color' => $dto->color,
        ]);
        parent::updateCalendar([$dto->calendarId, $dto->instanceId], $propPatch);
        $propPatch->commit();
    }

    public function updateCalendarOrderCustom(int $instanceId, int $order): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->calendarInstancesTableName . ' SET calendarorder = ? WHERE id = ?'
        );
        $stmt->execute([$order, $instanceId]);
    }

    public function deleteCalendarCustom(CalendarDTO $calendar): void
    {
        $this->deleteCalendar([$calendar->calendarId, $calendar->instanceId]);

        if ($calendar->isShared) {
            $this->recomputeSharedFlag($calendar->calendarId);
        }
    }

    public function getCalendarInvitesCustom(string $principalUri): array
    {
        return parent::getInvites($principalUri);
    }

    public function getEventsCustom(CalendarDTO $calendar): array
    {
        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            return array_map(function (array $object) use ($link, $calendar) {
                $event = EventDTO::fromSabre($this->objectRow($link, $object), $calendar);
                $event->storedData = $object['calendardata'];

                return $event;
            }, $this->remoteFor($link)->query($link, null));
        }

        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, etag, calendarid, size, calendardata, componenttype FROM '.$this->calendarObjectTableName.' WHERE calendarid = ?');
        $stmt->execute([$calendar->calendarId]);

        $events = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['calendardata'] = $this->readData($row['calendardata']);

            // storedData is for the calendar export, which writes the stored objects.
            $event = EventDTO::fromSabre($row, $calendar);
            $event->storedData = $row['calendardata'];
            $events[] = $event;
        }

        return $events;
    }

    /** @return array<int, string> */
    public function getObjectUidsCustom(CalendarDTO $calendar): array
    {
        $stmt = $this->pdo->prepare('SELECT uid FROM '.$this->calendarObjectTableName.' WHERE calendarid = ? AND uid IS NOT NULL');
        $stmt->execute([$calendar->calendarId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // Floating all-day dates pad the bounds by a day; the caller's occurrence loop is the exact filter.
    /**
     * @return array<int, array{id: int|string, uri: string, etag: string, calendardata: string|null}>
     */
    public function getEventObjectsInRange(CalendarDTO $calendar, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            $objects = $this->remoteFor($link)->query(
                $link,
                'VEVENT',
                \DateTime::createFromInterface($start)->modify('-1 day'),
                \DateTime::createFromInterface($end)->modify('+1 day'),
            );

            return array_map(fn (array $object) => [
                'id' => $this->externalObjectId($link, $object['uri']),
                'uri' => $object['uri'],
                'etag' => (string) $object['etag'],
                'calendardata' => $object['calendardata'],
            ], $objects);
        }

        $stmt = $this->pdo->prepare('SELECT id, uri, etag, calendardata FROM '.$this->calendarObjectTableName." WHERE calendarid = ? AND componenttype = 'VEVENT' AND (lastoccurence > ? OR lastoccurence >= ?) AND firstoccurence < ?");
        $paddedStart = $start->getTimestamp() - self::FLOATING_DATE_PADDING;
        $paddedEnd = $end->getTimestamp() + self::FLOATING_DATE_PADDING;
        $stmt->execute([$calendar->calendarId, $paddedStart, self::LAST_OCCURRENCE_CAP, $paddedEnd]);

        // Quoted like Sabre's getCalendarObject(), which this read replaced.
        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'uri' => $row['uri'],
            'etag' => '"'.$row['etag'].'"',
            'calendardata' => $this->readData($row['calendardata']),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function getExpandedEventsCustom(CalendarDTO $calendar, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $events = [];

        foreach ($this->getEventObjectsInRange($calendar, $start, $end) as $obj) {
            if (!$obj['calendardata']) {
                continue;
            }

            // Remote objects were never validated by sabre: one that cannot
            // be read or expanded is skipped, not allowed to fail the listing.
            try {
                array_push($events, ...$this->expandObject($obj, $calendar, $start, $end));
            } catch (\Throwable $e) {
                $this->logSkippedObject($calendar->calendarId, $obj['uri'], $e);
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $obj
     * @return array<int, EventDTO>
     */
    private function expandObject(array $obj, CalendarDTO $calendar, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $vObj = Reader::read($obj['calendardata']);
        $events = [];

        // Calendars also accept VTODOs from DAV clients; they have no VEVENT.
        foreach ($vObj->VEVENT ?? [] as $vevent) {
            if (!isset($vevent->DTSTART)) {
                throw new \UnexpectedValueException('VEVENT without DTSTART');
            }

            $startOrig = $vevent->DTSTART->getDateTime();
            $endOrig   = isset($vevent->DTEND) ? $vevent->DTEND->getDateTime() : $startOrig;
            $isAllDay = $vevent->DTSTART->getValueType() === 'DATE';

            // All-day events are stored as floating DATE values (the user's
            // local dates), which VObject parses as UTC. Rebase their
            // boundaries onto the query window's wall clock so midnight
            // boundaries line up for users outside UTC. Per RFC 5545 an
            // all-day event without DTEND spans exactly one day.
            if ($isAllDay) {
                $queryTz = $start->getTimezone();
                if (!isset($vevent->DTEND)) {
                    $endOrig = $endOrig->modify('+1 day');
                }
                $startOrig = new \DateTimeImmutable($startOrig->format('Y-m-d H:i:s'), $queryTz);
                $endOrig = new \DateTimeImmutable($endOrig->format('Y-m-d H:i:s'), $queryTz);
            }

            if (isset($vevent->RRULE)) {
                $rrule = (string) $vevent->RRULE;
                $rruleEnd = isset($vevent->RRULE['UNTIL']) ? $vevent->RRULE['UNTIL']->getDateTime() : null;

                // Compare wall-clock to wall-clock on both sides of the
                // window for all-day events: their floating DATE values
                // are parsed as UTC by VObject, while the window bounds
                // live in the query timezone. Rebase the bounds onto the
                // UTC wall clock so the boundary behavior is identical
                // for users east and west of UTC: an occurrence ending
                // exactly at the window start is skipped, and one
                // starting exactly at the window end is not emitted.
                $windowStart = $start;
                $windowEnd = $end;
                if ($isAllDay) {
                    $utc = new \DateTimeZone('UTC');
                    $windowStart = new \DateTimeImmutable($start->format('Y-m-d H:i:s'), $utc);
                    $windowEnd = new \DateTimeImmutable($end->format('Y-m-d H:i:s'), $utc);
                }

                $iterator = new \Sabre\VObject\Recur\EventIterator($vObj, (string)$vevent->UID);
                $iterator->fastForward($windowStart);

                while ($iterator->valid() && $iterator->getDtStart() < $windowEnd) {
                    $occStart = clone $iterator->getDtStart();
                    $occEnd = $iterator->getDtEnd() ? clone $iterator->getDtEnd() : null;
                    if ($isAllDay) {
                        $occStart = new \DateTimeImmutable($occStart->format('Y-m-d H:i:s'), $queryTz);
                        if ($occEnd) {
                            $occEnd = new \DateTimeImmutable($occEnd->format('Y-m-d H:i:s'), $queryTz);
                        }
                    }

                    if ($occEnd && $occEnd <= $start) {
                        $iterator->next();
                        continue;
                    }
                    if ($occStart > $end) {
                        break;
                    }

                    $occurrence = $iterator->getEventObject();

                    // The occurrence's original slot, which differs from its
                    // start once it was moved; clients address it by this.
                    $originalStart = isset($occurrence->{'RECURRENCE-ID'})
                        ? $occurrence->{'RECURRENCE-ID'}->getDateTime()
                        : $occStart;
                    if ($isAllDay) {
                        $originalStart = new \DateTimeImmutable($originalStart->format('Y-m-d H:i:s'), $queryTz);
                    }

                    $eventDto = EventDTO::fromSabre([
                        'id' => $obj['id'],
                        'uri' => $obj['uri'],
                        'etag' => $obj['etag'],
                        'calendardata' => $occurrence->serialize(),
                    ], $calendar);

                    $eventDto->originalStartDate = \DateTime::createFromInterface($originalStart);
                    $eventDto->recurrenceRule = $rrule;
                    $eventDto->recurrenceEnd = $rruleEnd;

                    $events[] = $eventDto;

                    $iterator->next();
                }
            } else if (!isset($vevent->{'RECURRENCE-ID'})) {
                // An event ending exactly at the window start, or starting
                // exactly at the window end, does not overlap it
                // (exclusive end, RFC 4791/5545).
                if ($endOrig <= $start || $startOrig >= $end) {
                    continue;
                }

                $events[] = EventDTO::fromSabre([
                    'id' => $obj['id'],
                    'uri' => $obj['uri'],
                    'etag' => $obj['etag'],
                    'calendardata' => $vevent->serialize(),
                ], $calendar);
            }
        }

        return $events;
    }


    public function getEventByUriCustom(CalendarDTO $calendar, string $uri): ?EventDTO
    {
        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            $object = $this->remoteFor($link)->getObject($link, $uri);
            if (!$object) {
                return null;
            }

            $event = EventDTO::fromSabre($this->objectRow($link, $object), $calendar);
            $event->storedData = $object['calendardata'];

            return $event;
        }

        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, etag, calendarid, size, calendardata, componenttype FROM '.$this->calendarObjectTableName.' WHERE calendarid = ? AND uri = ? LIMIT 1');
        $stmt->execute([$calendar->calendarId, $uri]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['calendardata'] = $this->readData($row['calendardata']);

        $event = EventDTO::fromSabre($row, $calendar);
        $event->storedData = $row['calendardata'];

        return $event;
    }

    public function createEventCustom(CalendarDTO $calendar, EventDTO $dto): ?string
    {
        $uri = bin2hex(Str::random(16)) . '.ics';

        // An event moved here from another calendar keeps its stored data.
        return $this->importEventCustom($calendar, $this->vCalForUpdate($calendar, $dto), $uri);
    }

    public function importEventCustom(CalendarDTO $calendar, string $vCal, ?string $uri = null): ?string
    {
        $uri ??= bin2hex(Str::random(16)) . '.ics';

        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            $this->putRemote($link, $uri, $vCal, true, null);

            return $uri;
        }

        return parent::createCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri, $vCal) ? $uri : null;
    }

    public function updateEventCustom(CalendarDTO $calendar, EventDTO $dto): bool
    {
        return $this->writeExisting($calendar, $dto->uri, $this->vCalForUpdate($calendar, $dto), $dto->etag);
    }

    private function vCalForUpdate(CalendarDTO $calendar, EventDTO $dto): string
    {
        try {
            return $dto->toVCalForUpdate();
        } catch (UnreadableCalendarObjectException $e) {
            $this->logSkippedObject($calendar->calendarId, (string) $dto->uri, $e->getPrevious() ?? $e);

            throw $e;
        }
    }

    public function deleteEventCustom(CalendarDTO $calendar, EventDTO $dto): bool
    {
        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            $this->deleteRemote($link, $dto->uri, $dto->etag);

            return true;
        }

        parent::deleteCalendarObject([$calendar->calendarId, $calendar->instanceId], $dto->uri);

        return $this->getEventByUriCustom($calendar, $dto->uri) === null;
    }

    public function updateInvites($calendarId, array $sharees): void
    {
        if (!is_array($calendarId)) {
            throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
        }
        if ($this->linkFor($calendarId)) {
            throw new Forbidden('External calendars cannot be shared.');
        }
        $currentInvites = $this->getInvites($calendarId);
        [$calendarId, $instanceId] = $calendarId;

        $removeStmt = $this->pdo->prepare('DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND share_href = ? AND access IN (' . \Sabre\DAV\Sharing\Plugin::ACCESS_READ . ',' . \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE . ')');
        $updateStmt = $this->pdo->prepare('UPDATE ' . $this->calendarInstancesTableName . ' SET access = ?, share_displayname = ?, share_invitestatus = ? WHERE calendarid = ? AND share_href = ?');
        $insertStmt = $this->pdo->prepare('
INSERT INTO ' . $this->calendarInstancesTableName . '
    (calendarid, principaluri, access, displayname, uri, description, calendarorder, calendarcolor, timezone, transparent, share_href, share_displayname, share_invitestatus)
    SELECT ?, ?, ?, displayname, ?, description, calendarorder, calendarcolor, timezone, 1, ?, ?, ?
    FROM ' . $this->calendarInstancesTableName . ' WHERE id = ?');

        foreach ($sharees as $sharee) {
            if (\Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS === $sharee->access) {
                $removeStmt->execute([$calendarId, $sharee->href]);
                continue;
            }

            if (is_null($sharee->principal)) {
                $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_INVALID;
            } else {
                $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE;
            }

            foreach ($currentInvites as $oldSharee) {
                if ($oldSharee->href === $sharee->href) {
                    $sharee->properties = array_merge($oldSharee->properties, $sharee->properties);
                    $updateStmt->execute([
                        $sharee->access,
                        $sharee->properties['{DAV:}displayname'] ?? null,
                        // Sharing again changes the access, not the sharee's answer.
                        $oldSharee->inviteStatus ?: $sharee->inviteStatus,
                        $calendarId,
                        $sharee->href,
                    ]);
                    continue 2;
                }
            }

            $insertStmt->execute([
                $calendarId,
                $sharee->principal,
                $sharee->access,
                \Sabre\DAV\UUIDUtil::getUUID(),
                $sharee->href,
                $sharee->properties['{DAV:}displayname'] ?? null,
                $sharee->inviteStatus ?: \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE,
                $instanceId,
            ]);
        }
    }

    public function shareCalendarWithUserCustom(
        User $sender,
        User $receiver,
        CalendarDTO $calendar,
        bool $writeAccess
    )
    {
        $this->updateInvites([$calendar->calendarId, $calendar->instanceId], [
            new Sharee([
                'href' => 'mailto:' . $receiver->email,
                'principal' => DavHelper::getPrincipalUri($receiver),
                'access' => $writeAccess ? 3 : 2,
                'properties' => [
                    '{DAV:}displayname' => $calendar->displayName . ' (' . $sender->name . ')',
                ]
            ])
        ]);
    }

    // The token is the sharee's mailto: href, not a secret, so the update is scoped to the caller's own pending instance.
    public function acceptShare(string $principalUri, string $shareToken): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, calendarid FROM ' . $this->calendarInstancesTableName . ' WHERE share_href = ? AND principaluri = ? AND share_invitestatus = ' . \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE
        );
        $stmt->execute([$shareToken, $principalUri]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->calendarInstancesTableName . ' SET share_invitestatus = ' . \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED . ' WHERE id = ?'
        );
        $stmt->execute([$row['id']]);

        $calendarId = (int) $row['calendarid'];
        $this->recomputeSharedFlag($calendarId);

        return $calendarId;
    }

    // Scoped like acceptShare(). Only a pending invite can be declined; an accepted share is left through unsubscribe.
    public function declineShare(string $principalUri, string $shareToken): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE share_href = ? AND principaluri = ? AND access > ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER . ' AND share_invitestatus = ' . \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE
        );
        $stmt->execute([$shareToken, $principalUri]);

        return $stmt->rowCount() > 0;
    }

    public function unshareCalendar(int $calendarId, string $recipientEmail): void
    {
        $principalUri = 'principals/' . $recipientEmail;
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND principaluri = ? AND access > ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER
        );
        $stmt->execute([$calendarId, $principalUri]);

        $this->recomputeSharedFlag($calendarId);
    }

    public function deleteSharedInstancesCustom(string $principalUri): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT calendarid FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access > ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER
        );
        $stmt->execute([$principalUri]);
        $calendarIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access > ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER
        );
        $stmt->execute([$principalUri]);
        $deleted = $stmt->rowCount();

        foreach ($calendarIds as $calendarId) {
            $this->recomputeSharedFlag($calendarId);
        }

        return $deleted;
    }

    // The owner plus accepted sharees: the users who load the calendar's events.
    /** @return array<int, string> */
    public function getCalendarMemberIds(int $calendarId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT principaluri FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND (access = ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER . ' OR share_invitestatus = ' . \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED . ')'
        );
        $stmt->execute([$calendarId]);

        return $this->userIdsForPrincipals($stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function getCalendarOwnerId(int $calendarId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT principaluri FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND access = ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER
        );
        $stmt->execute([$calendarId]);

        return $this->userIdsForPrincipals($stmt->fetchAll(\PDO::FETCH_COLUMN))[0] ?? null;
    }

    public function getCalendarSharees(int $calendarId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT principaluri, access, share_invitestatus FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND access > ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER
        );
        $stmt->execute([$calendarId]);

        $sharees = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $user = User::where('email', $this->principalEmail($row['principaluri']))->first();

            if ($user) {
                $sharees[] = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'access' => (int) $row['access'],
                    'status' => match ((int) $row['share_invitestatus']) {
                        1 => 'pending',
                        2 => 'accepted',
                        3 => 'declined',
                        default => 'unknown',
                    },
                ];
            }
        }

        return $sharees;
    }

    // An EXDATE for the original slot, and its exception VEVENT if it was moved or edited; the master is otherwise untouched.
    public function deleteOccurrenceCustom(CalendarDTO $calendar, string $uri, \DateTimeInterface $occurrenceDate): bool
    {
        [$vCal, $masterEvent, $etag] = $this->readRecurringObject($calendar, $uri);

        if (!$masterEvent) {
            return false;
        }

        $isAllDay = $masterEvent->DTSTART->getValueType() === 'DATE';
        $tz = $masterEvent->DTSTART->getDateTime()->getTimezone();
        $slot = $this->resolveOccurrenceSlot($vCal, $masterEvent, $occurrenceDate, $isAllDay);

        VEventHelper::addDateProperty($masterEvent, 'EXDATE', $slot, $isAllDay, $tz);

        $existing = $this->findException($vCal, $slot, $isAllDay);
        if ($existing) {
            $vCal->remove($existing);
        }

        return $this->writeExisting($calendar, $uri, $vCal->serialize(), $etag);
    }

    // Through the occurrence's exception VEVENT, or else a copy of the master, so alarms and other client data
    // carry over. The master is untouched.
    public function updateOccurrenceCustom(
        CalendarDTO $calendar,
        string $uri,
        \DateTimeInterface $occurrenceDate,
        EventDTO $updatedData
    ): bool {
        [$vCal, $masterEvent, $etag] = $this->readRecurringObject($calendar, $uri);

        if (!$masterEvent || !isset($masterEvent->UID) || !$updatedData->startDate) {
            return false;
        }

        $isAllDay = $masterEvent->DTSTART->getValueType() === 'DATE';
        $tz = $masterEvent->DTSTART->getDateTime()->getTimezone();
        $slot = $this->resolveOccurrenceSlot($vCal, $masterEvent, $occurrenceDate, $isAllDay);

        $exceptionEvent = $this->findException($vCal, $slot, $isAllDay);
        if (!$exceptionEvent) {
            $exceptionEvent = clone $masterEvent;
            foreach (['RRULE', 'RDATE', 'EXDATE', 'EXRULE'] as $name) {
                $exceptionEvent->remove($name);
            }
            VEventHelper::addDateProperty($exceptionEvent, 'RECURRENCE-ID', $slot, $isAllDay, $tz);
            $vCal->add($exceptionEvent);
        }

        $exceptionEvent->SUMMARY = $updatedData->title;
        $exceptionEvent->DTSTAMP = gmdate('Ymd\THis\Z');
        VEventHelper::removeDates($exceptionEvent);

        VEventHelper::addDateProperty($exceptionEvent, 'DTSTART', $updatedData->startDate, $isAllDay, $tz);
        if ($updatedData->endDate) {
            // An all-day DTEND is exclusive: the day after the last one.
            $end = $isAllDay ? \DateTime::createFromInterface($updatedData->endDate)->modify('+1 day') : $updatedData->endDate;
            VEventHelper::addDateProperty($exceptionEvent, 'DTEND', $end, $isAllDay, $tz);
        }

        VEventHelper::setDescriptionAndLocation($exceptionEvent, $updatedData->description, $updatedData->location);

        return $this->writeExisting($calendar, $uri, $vCal->serialize(), $etag);
    }

    /**
     * @param array<int, string> $principalUris
     * @return array<int, string>
     */
    private function userIdsForPrincipals(array $principalUris): array
    {
        if ($principalUris === []) {
            return [];
        }

        $emails = array_map(fn (string $uri) => $this->principalEmail($uri), $principalUris);

        return User::whereIn('email', $emails)->pluck('id')->all();
    }

    private function principalEmail(string $principalUri): string
    {
        return str_replace('principals/', '', $principalUri);
    }

    // Recomputed rather than toggled, so the owner's solyto_shared can't drift from the accepted sharee rows.
    private function recomputeSharedFlag(int $calendarId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->calendarInstancesTableName . ' SET solyto_shared = EXISTS(
                SELECT 1 FROM ' . $this->calendarInstancesTableName . ' s
                WHERE s.calendarid = ? AND s.access > ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER . ' AND s.share_invitestatus = ' . \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED . '
            ) WHERE calendarid = ? AND access = ' . \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER
        );
        $stmt->execute([$calendarId, $calendarId]);
    }

    /**
     * @return array{0: ?\Sabre\VObject\Component\VCalendar, 1: ?\Sabre\VObject\Component\VEvent, 2: ?string}
     *
     * @throws UnreadableCalendarObjectException
     */
    private function readRecurringObject(CalendarDTO $calendar, string $uri): array
    {
        $obj = $this->getCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri);

        if (!$obj) {
            return [null, null, null];
        }

        $vCalData = $this->readData($obj['calendardata']);

        if (!$vCalData) {
            return [null, null, null];
        }

        // Remote objects were never validated by sabre (see getExpandedEventsCustom).
        try {
            $vCal = Reader::read($vCalData);

            foreach ($vCal->VEVENT ?? [] as $vevent) {
                if (!isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->RRULE)) {
                    if (!isset($vevent->DTSTART)) {
                        throw new \UnexpectedValueException('VEVENT without DTSTART');
                    }

                    return [$vCal, $vevent, $obj['etag'] ?? null];
                }
            }
        } catch (\Throwable $e) {
            $this->logSkippedObject($calendar->calendarId, $uri, $e);

            throw new UnreadableCalendarObjectException();
        }

        return [$vCal, null, $obj['etag'] ?? null];
    }

    // Native calendars ignore the ETag as before; external calendars send it as If-Match, so the remote refuses (412)
    // when the object changed there since it was read.
    private function writeExisting(CalendarDTO $calendar, string $uri, string $data, ?string $etag): bool
    {
        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            $this->putRemote($link, $uri, $data, false, $etag);

            return true;
        }

        return parent::updateCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri, $data) !== null;
    }

    private function putRemote(ExternalCalendar $link, string $uri, string $data, bool $create, ?string $ifMatch): ?string
    {
        $etag = $this->remoteFor($link)->putObject($link, $uri, $data, $create, $ifMatch);
        $this->markStateStale($link);

        return $etag;
    }

    private function deleteRemote(ExternalCalendar $link, string $uri, ?string $ifMatch): void
    {
        $this->remoteFor($link)->deleteObject($link, $uri, $ifMatch);
        $this->markStateStale($link);
    }

    /** @param  int|array{0: int, 1: int}  $calendarId */
    private function linkFor(int|array $calendarId): ?ExternalCalendar
    {
        return $this->externalCalendar((int) (is_array($calendarId) ? $calendarId[0] : $calendarId));
    }

    /** @param  int|array{0: int, 1: int}  $calendarId */
    private function isExternal(int|array $calendarId): bool
    {
        return $this->linkFor($calendarId) !== null;
    }

    // One query per principal and request: every calendar of the listing is marked native or external,
    // so object calls that follow need no lookup.
    /** @param  array<int, array<string, mixed>>  $calendars */
    private function loadExternal(string $principalUri, array $calendars): void
    {
        if (!isset($this->loadedPrincipals[$principalUri])) {
            $this->loadedPrincipals[$principalUri] = true;

            $links = ExternalCalendar::with('account')
                ->whereIn('user_id', User::select('id')->where('email', $this->principalEmail($principalUri)))
                ->get();

            foreach ($links as $link) {
                $this->external[$link->calendar_id] = $link;
            }
        }

        foreach ($calendars as $calendar) {
            $this->external[(int) $calendar['id'][0]] ??= false;
        }
    }

    // When the remote is down the last known ctag and sync token are served (and kept for another TTL), so the
    // calendar never drops out of the home listing: a client would delete its copy. Requests into it then get a 503.
    /**
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    private function withRemoteState(array $calendar): array
    {
        $link = $this->linkFor($calendar['id']);
        if (!$link) {
            return $calendar;
        }

        unset($calendar['{'.Plugin::NS_CALENDARSERVER.'}getctag'], $calendar['{http://sabredav.org/ns}sync-token']);
        $calendar['is-external'] = true;

        if (!$this->mayReachRemote($link)) {
            return $calendar;
        }

        $state = $this->remoteState($link);
        $syncToken = $state['sync_token'] !== null ? $this->encodeSyncToken($state['sync_token']) : null;

        if ($syncToken !== null) {
            $calendar['{http://sabredav.org/ns}sync-token'] = $syncToken;
        }

        $ctagFromSyncToken = $syncToken !== null ? 'http://sabre.io/ns/sync/'.$syncToken : null;
        $ctag = $state['ctag'] ?? $ctagFromSyncToken;
        if ($ctag !== null) {
            $calendar['{'.Plugin::NS_CALENDARSERVER.'}getctag'] = $ctag;
        }

        return $calendar;
    }

    /** @return array{ctag: ?string, sync_token: ?string} */
    private function remoteState(ExternalCalendar $link): array
    {
        $cache = app(UserCacheService::class);
        $key = $this->stateKey($link);
        $cached = $cache->get($key);

        $isFresh = is_array($cached) && $cached['fetched_at'] > time() - (int) config('calendars.external.cache_ttl');
        if ($isFresh) {
            return $cached['state'];
        }

        $unavailableKey = [self::CACHE_KEY_UNAVAILABLE, $link->user_id, $link->calendar_id];
        $unavailableTags = CalendarService::unavailableCacheTags($link->user_id);
        if ($cache->getTagged($unavailableTags, $unavailableKey)) {
            return is_array($cached) ? $cached['state'] : ['ctag' => null, 'sync_token' => null];
        }

        try {
            $state = $this->remoteFor($link)->collectionState($link);
        } catch (ExternalCalendarUnavailableException $e) {
            // Sabre resolves the home on every request, native calendars
            // included: remember the failure so a dead remote is asked at
            // most once per TTL.
            $state = is_array($cached) ? $cached['state'] : ['ctag' => null, 'sync_token' => null];
            $cache->storeTagged($unavailableTags, $unavailableKey, (int) config('calendars.external.unavailable_ttl'), $e->reason());
        }

        $cache->store($key, 0, ['fetched_at' => time(), 'state' => $state]);

        return $state;
    }

    // Throwable, since odd remote property types surface as \Error. Only the class is logged: parser messages
    // can quote calendar data.
    private function logSkippedObject(int $calendarId, string $uri, \Throwable $e): void
    {
        Log::channel('dav')->warning('Calendar object skipped: cannot be parsed', [
            'calendar_id' => $calendarId,
            'uri' => $uri,
            'error' => $e::class,
        ]);
    }

    private function markStateStale(ExternalCalendar $link): void
    {
        $cache = app(UserCacheService::class);
        $key = $this->stateKey($link);
        $cached = $cache->get($key);

        if (is_array($cached)) {
            $cache->store($key, 0, ['fetched_at' => 0, 'state' => $cached['state']]);
        }
    }

    /** @return array<int, string|int> */
    private function stateKey(ExternalCalendar $link): array
    {
        return [self::CACHE_KEY_STATE, $link->user_id, $link->calendar_id];
    }

    // Stream from pgsql's BYTEA, a string from SQLite and the remote.
    private function readData(mixed $data): mixed
    {
        return is_resource($data) ? stream_get_contents($data) : $data;
    }

    private function encodeSyncToken(string $remoteToken): string
    {
        return self::SYNC_TOKEN_MARKER.rtrim(strtr(base64_encode($remoteToken), '+/', '-_'), '=');
    }

    private function decodeSyncToken(string $token): ?string
    {
        if (!str_starts_with($token, self::SYNC_TOKEN_MARKER)) {
            return null;
        }

        $decoded = base64_decode(strtr(substr($token, strlen(self::SYNC_TOKEN_MARKER)), '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * @param  array{uri: string, etag: ?string, size: int, calendardata?: string}  $object
     * @return array<string, mixed>
     */
    private function objectRow(ExternalCalendar $link, array $object): array
    {
        $row = [
            'id' => $this->externalObjectId($link, $object['uri']),
            'uri' => $object['uri'],
            'etag' => $object['etag'],
            'size' => $object['size'],
            'lastmodified' => null,
            'calendarid' => [$link->calendar_id, $link->instance_id],
        ];
        if (isset($object['calendardata'])) {
            $row['calendardata'] = $object['calendardata'];
        }

        return $row;
    }

    // External events have no calendarobjects row, so their id (used to key event attachments) is derived from the
    // link and the object name: stable while the remote keeps the file name, and in [2^52, 2^53) so it never meets
    // a native autoincrement id and stays exact as a JSON number.
    private function externalObjectId(ExternalCalendar $link, string $uri): int
    {
        $hashBits = (int) hexdec(substr(hash('sha256', $link->id.'/'.$uri), 0, 13));

        return (1 << 52) | $hashBits;
    }

    private function remoteFor(ExternalCalendar $link): RemoteCalDavClient
    {
        if (!$this->mayReachRemote($link)) {
            throw new Forbidden();
        }

        return app(RemoteCalDavClient::class);
    }

    // Sabre checks privileges only after loading a calendar, and loading an external one calls the remote
    // with its owner's credentials. External calendars can't be shared, so only their owner may get that far.
    private function mayReachRemote(ExternalCalendar $link): bool
    {
        return $this->requestPrincipal === null || $this->requestPrincipal === 'principals/'.$link->user->email;
    }

    // The original slot (RECURRENCE-ID) a client means by $occurrenceDate, in this order: an exception's
    // RECURRENCE-ID, a regular occurrence of the series, an exception moved to that time (older clients sent the
    // moved time), else $occurrenceDate itself.
    private function resolveOccurrenceSlot(\Sabre\VObject\Component\VCalendar $vCal, \Sabre\VObject\Component\VEvent $masterEvent, \DateTimeInterface $occurrenceDate, bool $isAllDay): \DateTimeInterface
    {
        $exception = $this->findException($vCal, $occurrenceDate, $isAllDay);
        if ($exception) {
            return $exception->{'RECURRENCE-ID'}->getDateTime();
        }

        $series = new \Sabre\VObject\Recur\EventIterator([$masterEvent]);
        $series->fastForward(\DateTime::createFromInterface($occurrenceDate)->modify('-1 day'));
        while ($series->valid() && $series->getDtStart() <= \DateTime::createFromInterface($occurrenceDate)->modify('+1 day')) {
            if (VEventHelper::isSameSlot($series->getDtStart(), $occurrenceDate, $isAllDay)) {
                return $occurrenceDate;
            }
            $series->next();
        }

        foreach ($vCal->select('VEVENT') as $vevent) {
            $isMovedHere = isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->DTSTART)
                && VEventHelper::isSameSlot($vevent->DTSTART->getDateTime(), $occurrenceDate, $isAllDay);
            if ($isMovedHere) {
                return $vevent->{'RECURRENCE-ID'}->getDateTime();
            }
        }

        return $occurrenceDate;
    }

    private function findException(\Sabre\VObject\Component\VCalendar $vCal, \DateTimeInterface $slot, bool $isAllDay): ?\Sabre\VObject\Component\VEvent
    {
        foreach ($vCal->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                continue;
            }
            if (VEventHelper::isSameSlot($vevent->{'RECURRENCE-ID'}->getDateTime(), $slot, $isAllDay)) {
                return $vevent;
            }
        }

        return null;
    }
}
