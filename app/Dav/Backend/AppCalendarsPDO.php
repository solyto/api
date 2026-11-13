<?php

namespace App\Dav\Backend;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Exceptions\ExternalCalendarUnavailableException;
use App\Dav\Helpers\DavHelper;
use App\Dav\Services\RemoteCalDavClient;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Str;
use Sabre\CalDAV\Backend\PDO as SabreBackend;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Xml\Element\Sharee;
use Sabre\VObject\Reader;

/**
 * External calendars (ExternalCalendar link rows) are native calendar rows
 * without objects: every object-level call on them goes to the user's own
 * CalDAV server through RemoteCalDavClient, and calendar-level calls stay
 * local. Native calendars take the parent's code paths unchanged.
 */
class AppCalendarsPDO extends SabreBackend
{
    private const string SYNC_TOKEN_MARKER = 'ext-';
    private const string CACHE_KEY_STATE = 'external_calendar_state';

    /** @var array<int, ExternalCalendar|false> pgsql calendarid => link, false for native */
    private array $external = [];

    /** @var array<string, true> */
    private array $loadedPrincipals = [];

    /** The If-Match of the current DAV request, passed on to remote writes. */
    private ?string $ifMatch = null;

    /**
     * Objects a calendar-query already read with their data, handed to the
     * multiget sabre runs right after it so they are not fetched twice.
     *
     * @var array<int, array<string, array<string, mixed>>>
     */
    private array $prefetched = [];

    /**
     * Calendars shared with the principal reach DAV clients only once the
     * invite is accepted, as in the app. listCalendarsCustom() reads the
     * parent directly, so the app still sees pending invites.
     */
    public function getCalendarsForUser($principalUri)
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access > 1 AND share_invitestatus <> ?'
        );
        $stmt->execute([$principalUri, \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED]);
        $notAccepted = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $calendars = array_values(array_filter(
            parent::getCalendarsForUser($principalUri),
            fn (array $calendar) => !in_array($calendar['id'][1], $notAccepted, true)
        ));

        $this->loadExternal($principalUri, $calendars);

        return array_map(fn (array $calendar) => $this->withRemoteState($calendar), $calendars);
    }

    public function setRequestPreconditions(?string $ifMatch): void
    {
        $this->ifMatch = $ifMatch !== '' ? $ifMatch : null;
    }

    public function externalCalendar(int $calendarId): ?ExternalCalendar
    {
        if (!array_key_exists($calendarId, $this->external)) {
            $this->external[$calendarId] = ExternalCalendar::with('account')->where('calendar_id', $calendarId)->first() ?? false;
        }

        return $this->external[$calendarId] ?: null;
    }

    /**
     * Drops what this instance remembers about a calendar after it was
     * linked or unlinked, so the next call looks it up again.
     */
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

        $object = $this->remote()->getObject($link, $objectUri);

        return $object ? $this->objectRow($link, $object) : null;
    }

    public function getCalendarObjects($calendarId)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            return parent::getCalendarObjects($calendarId);
        }

        return array_map(fn (array $object) => $this->objectRow($link, $object), $this->remote()->listObjects($link));
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

        foreach ($this->remote()->multiget($link, $missing) as $object) {
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

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $link = $this->linkFor($calendarId);
        if (!$link) {
            parent::deleteCalendarObject($calendarId, $objectUri);

            return;
        }

        $this->deleteRemote($link, $objectUri, $this->ifMatch);
    }

    /**
     * The remote narrows by component and time range; the exact filter runs
     * here on the returned data, as sabre does for its own post-filter.
     */
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
        foreach ($this->remote()->query($link, $component, $start, $end) as $object) {
            $row = $this->objectRow($link, $object);
            if ($this->validateFilterForObject($row, $filters)) {
                $uris[] = $row['uri'];
                $this->prefetched[$link->calendar_id][$row['uri']] = $row;
            }
        }

        return $uris;
    }

    /**
     * The remote sync token travels behind sabre's prefix as
     * "ext-" + base64url, so it survives as a URI suffix whatever it looks
     * like. A token that is not ours or that the remote refuses makes the
     * client resync from scratch.
     */
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

        $changes = $this->remote()->syncCollection($link, $remoteToken);
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
            app(UserCacheService::class)->forget([self::CACHE_KEY_STATE, $link->user_id, $link->calendar_id]);
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
             LEFT JOIN ' . $this->calendarInstancesTableName . ' o ON o.calendarid = ci.calendarid AND o.access = 1
             WHERE ci.id = ?'
        );

        // parent::getCalendarsForUser() names its own columns.
        $flagged = $this->pdo->prepare(
            'SELECT id FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access = 1 AND solyto_shared'
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
                    $email = str_replace('principals/', '', $row['owner_principaluri']);
                    $entry['owner-name'] = User::where('email', $email)->value('name');
                }
            }
            $entry['is-external'] = $this->linkFor($entry['id']) !== null;
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

        $calendar['is-external'] = $this->linkFor($calendar['id']) !== null;

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

        $calendar['is-external'] = $this->linkFor($calendar['id']) !== null;

        return CalendarDTO::fromSabre($calendar);
    }

    public function createCalendarCustom(string $principalUri, CalendarDTO $dto)
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

        parent::createCalendar($principalUri, Str::slug($dto->displayName), $calendar);
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
            }, $this->remote()->query($link, null));
        }

        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, etag, calendarid, size, calendardata, componenttype FROM '.$this->calendarObjectTableName.' WHERE calendarid = ?');
        $stmt->execute([$calendar->calendarId]);

        $events = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['calendardata'] = is_resource($row['calendardata'])
                ? stream_get_contents($row['calendardata'])
                : $row['calendardata'];

            // Used by the calendar export, which writes the stored objects.
            $event = EventDTO::fromSabre($row, $calendar);
            $event->storedData = $row['calendardata'];
            $events[] = $event;
        }

        return $events;
    }

    /**
     * The VEVENT objects that may overlap the window, read in one query.
     * Sabre stores floating all-day DATE values as UTC, up to 14 h off the
     * user's wall clock, so the bounds are padded by a day; the caller's
     * occurrence loop is the exact filter.
     *
     * @return array<int, array{id: int|string, uri: string, etag: string, calendardata: string|null}>
     */
    public function getEventObjectsInRange(CalendarDTO $calendar, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            $objects = $this->remote()->query(
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

        $stmt = $this->pdo->prepare('SELECT id, uri, etag, calendardata FROM '.$this->calendarObjectTableName." WHERE calendarid = ? AND componenttype = 'VEVENT' AND lastoccurence > ? AND firstoccurence < ?");
        $stmt->execute([$calendar->calendarId, $start->getTimestamp() - 86400, $end->getTimestamp() + 86400]);

        // Quoted like Sabre's getCalendarObject(), which this read replaced.
        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'uri' => $row['uri'],
            'etag' => '"'.$row['etag'].'"',
            'calendardata' => is_resource($row['calendardata'])
                ? stream_get_contents($row['calendardata'])
                : $row['calendardata'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function getExpandedEventsCustom(CalendarDTO $calendar, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $events = [];

        foreach ($this->getEventObjectsInRange($calendar, $start, $end) as $obj) {
            $vCalData = $obj['calendardata'];

            if (!$vCalData) {
                continue;
            }

            $vObj = \Sabre\VObject\Reader::read($vCalData);

            // Calendars also accept VTODOs from DAV clients; they have no VEVENT.
            foreach ($vObj->VEVENT ?? [] as $vevent) {
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

                        // Skip occurrences outside requested range
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
                    // Skip single-instance events outside the requested range.
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
        }

        return $events;
    }


    public function getEventByUriCustom(CalendarDTO $calendar, string $uri): ?EventDTO
    {
        $link = $this->externalCalendar($calendar->calendarId);
        if ($link) {
            $object = $this->remote()->getObject($link, $uri);
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

        $row['calendardata'] = is_resource($row['calendardata'])
            ? stream_get_contents($row['calendardata'])
            : $row['calendardata'];

        $event = EventDTO::fromSabre($row, $calendar);
        $event->storedData = $row['calendardata'];

        return $event;
    }

    public function createEventCustom(CalendarDTO $calendar, EventDTO $dto): ?string
    {
        $uri = bin2hex(Str::random(16)) . '.ics';

        // An event moved here from another calendar keeps its stored data.
        return $this->importEventCustom($calendar, $dto->toVCalForUpdate(), $uri);
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
        return $this->writeExisting($calendar, $dto->uri, $dto->toVCalForUpdate(), $dto->etag);
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

        $removeStmt = $this->pdo->prepare('DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND share_href = ? AND access IN (2,3)');
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

    /**
     * The token is the sharee's mailto: href, not a secret, so the update is
     * scoped to the caller's own pending instance. Returns the calendar id,
     * or null if none matched.
     */
    public function acceptShare(string $principalUri, string $shareToken): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, calendarid FROM ' . $this->calendarInstancesTableName . ' WHERE share_href = ? AND principaluri = ? AND share_invitestatus = 1'
        );
        $stmt->execute([$shareToken, $principalUri]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->calendarInstancesTableName . ' SET share_invitestatus = 2 WHERE id = ?'
        );
        $stmt->execute([$row['id']]);

        $calendarId = (int) $row['calendarid'];
        $this->recomputeSharedFlag($calendarId);

        return $calendarId;
    }

    /**
     * Scoped like acceptShare(). Only a pending invite can be declined; an
     * accepted share is left through unsubscribe.
     */
    public function declineShare(string $principalUri, string $shareToken): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE share_href = ? AND principaluri = ? AND access > 1 AND share_invitestatus = 1'
        );
        $stmt->execute([$shareToken, $principalUri]);

        return $stmt->rowCount() > 0;
    }

    public function unshareCalendar(int $calendarId, string $recipientEmail): void
    {
        $principalUri = 'principals/' . $recipientEmail;
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND principaluri = ? AND access > 1'
        );
        $stmt->execute([$calendarId, $principalUri]);

        $this->recomputeSharedFlag($calendarId);
    }

    public function deleteSharedInstancesCustom(string $principalUri): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT calendarid FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access > 1'
        );
        $stmt->execute([$principalUri]);
        $calendarIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access > 1'
        );
        $stmt->execute([$principalUri]);
        $deleted = $stmt->rowCount();

        foreach ($calendarIds as $calendarId) {
            $this->recomputeSharedFlag($calendarId);
        }

        return $deleted;
    }

    /**
     * The owner plus accepted sharees: the users who load the calendar's events.
     *
     * @return array<int, string>
     */
    public function getCalendarMemberIds(int $calendarId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT principaluri FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND (access = 1 OR share_invitestatus = 2)'
        );
        $stmt->execute([$calendarId]);

        return $this->userIdsForPrincipals($stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function getCalendarOwnerId(int $calendarId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT principaluri FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND access = 1'
        );
        $stmt->execute([$calendarId]);

        return $this->userIdsForPrincipals($stmt->fetchAll(\PDO::FETCH_COLUMN))[0] ?? null;
    }

    public function getCalendarSharees(int $calendarId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT principaluri, access, share_invitestatus FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND access > 1'
        );
        $stmt->execute([$calendarId]);

        $sharees = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $email = str_replace('principals/', '', $row['principaluri']);
            $user = User::where('email', $email)->first();

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

    /**
     * Delete a single occurrence from a recurring event: an EXDATE for its
     * original slot, and its exception VEVENT if it was moved or edited.
     * This does NOT modify the master event's other properties.
     */
    public function deleteOccurrenceCustom(CalendarDTO $calendar, string $uri, \DateTimeInterface $occurrenceDate): bool
    {
        [$vCal, $masterEvent, $etag] = $this->readRecurringObject($calendar, $uri);

        if (!$masterEvent) {
            return false;
        }

        $isAllDay = $masterEvent->DTSTART->getValueType() === 'DATE';
        $tz = $masterEvent->DTSTART->getDateTime()->getTimezone();
        $slot = $this->resolveOccurrenceSlot($vCal, $masterEvent, $occurrenceDate, $isAllDay);

        if ($isAllDay) {
            $masterEvent->add('EXDATE', $slot->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $slotInTz = \DateTime::createFromInterface($slot)->setTimezone($tz);
            $masterEvent->add('EXDATE', $slotInTz->format('Ymd\THis'), ['TZID' => $tz->getName()]);
        }

        $existing = $this->findException($vCal, $slot, $isAllDay);
        if ($existing) {
            $vCal->remove($existing);
        }

        return $this->writeExisting($calendar, $uri, $vCal->serialize(), $etag);
    }

    /**
     * Update a single occurrence of a recurring event through an exception
     * VEVENT with RECURRENCE-ID: the occurrence's existing exception if it was
     * already moved or edited, otherwise a copy of the master, so alarms and
     * other client data carry over. This does NOT modify the master event.
     */
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
            if ($isAllDay) {
                $exceptionEvent->add('RECURRENCE-ID', $slot->format('Ymd'), ['VALUE' => 'DATE']);
            } else {
                $slotInTz = \DateTime::createFromInterface($slot)->setTimezone($tz);
                $exceptionEvent->add('RECURRENCE-ID', $slotInTz->format('Ymd\THis'), ['TZID' => $tz->getName()]);
            }
            $vCal->add($exceptionEvent);
        }

        $exceptionEvent->SUMMARY = $updatedData->title;
        $exceptionEvent->DTSTAMP = gmdate('Ymd\THis\Z');
        $exceptionEvent->remove('DTSTART');
        $exceptionEvent->remove('DTEND');
        $exceptionEvent->remove('DURATION');

        if ($isAllDay) {
            $exceptionEvent->add('DTSTART', $updatedData->startDate->format('Ymd'), ['VALUE' => 'DATE']);
            if ($updatedData->endDate) {
                $end = \DateTime::createFromInterface($updatedData->endDate)->modify('+1 day');
                $exceptionEvent->add('DTEND', $end->format('Ymd'), ['VALUE' => 'DATE']);
            }
        } else {
            // The request's times are in the user's zone; store them in the series' zone.
            $startInTz = \DateTime::createFromInterface($updatedData->startDate)->setTimezone($tz);
            $exceptionEvent->add('DTSTART', $startInTz->format('Ymd\THis'), ['TZID' => $tz->getName()]);
            if ($updatedData->endDate) {
                $endInTz = \DateTime::createFromInterface($updatedData->endDate)->setTimezone($tz);
                $exceptionEvent->add('DTEND', $endInTz->format('Ymd\THis'), ['TZID' => $tz->getName()]);
            }
        }

        foreach (['DESCRIPTION' => $updatedData->description, 'LOCATION' => $updatedData->location] as $name => $value) {
            if ($value) {
                $exceptionEvent->{$name} = $value;
            } else {
                $exceptionEvent->remove($name);
            }
        }

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

        $emails = array_map(fn (string $uri) => str_replace('principals/', '', $uri), $principalUris);

        return User::whereIn('email', $emails)->pluck('id')->all();
    }

    /**
     * Recomputed rather than toggled, so the owner's solyto_shared can't
     * drift from the accepted sharee rows.
     */
    private function recomputeSharedFlag(int $calendarId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->calendarInstancesTableName . ' SET solyto_shared = EXISTS(
                SELECT 1 FROM ' . $this->calendarInstancesTableName . ' s
                WHERE s.calendarid = ? AND s.access > 1 AND s.share_invitestatus = 2
            ) WHERE calendarid = ? AND access = 1'
        );
        $stmt->execute([$calendarId, $calendarId]);
    }

    /**
     * @return array{0: ?\Sabre\VObject\Component\VCalendar, 1: ?\Sabre\VObject\Component\VEvent, 2: ?string}
     */
    private function readRecurringObject(CalendarDTO $calendar, string $uri): array
    {
        $obj = $this->getCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri);

        if (!$obj) {
            return [null, null, null];
        }

        $vCalData = is_resource($obj['calendardata'])
            ? stream_get_contents($obj['calendardata'])
            : $obj['calendardata'];

        if (!$vCalData) {
            return [null, null, null];
        }

        $vCal = Reader::read($vCalData);

        foreach ($vCal->VEVENT ?? [] as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->RRULE)) {
                return [$vCal, $vevent, $obj['etag'] ?? null];
            }
        }

        return [$vCal, null, $obj['etag'] ?? null];
    }

    /**
     * Writes an object that already exists. Native calendars ignore the
     * ETag as before; external calendars send it as If-Match, so the remote
     * refuses (412) when the object changed there since it was read.
     */
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
        $etag = $this->remote()->putObject($link, $uri, $data, $create, $ifMatch);
        $this->markStateStale($link);

        return $etag;
    }

    private function deleteRemote(ExternalCalendar $link, string $uri, ?string $ifMatch): void
    {
        $this->remote()->deleteObject($link, $uri, $ifMatch);
        $this->markStateStale($link);
    }

    /**
     * @param  int|array{0: int, 1: int}  $calendarId
     */
    private function linkFor(int|array $calendarId): ?ExternalCalendar
    {
        return $this->externalCalendar((int) (is_array($calendarId) ? $calendarId[0] : $calendarId));
    }

    /**
     * One query per principal and request: every calendar of the listing is
     * marked native or external, so object calls that follow need no lookup.
     *
     * @param  array<int, array<string, mixed>>  $calendars
     */
    private function loadExternal(string $principalUri, array $calendars): void
    {
        if (!isset($this->loadedPrincipals[$principalUri])) {
            $this->loadedPrincipals[$principalUri] = true;

            $links = ExternalCalendar::with('account')
                ->whereIn('user_id', User::select('id')->where('email', str_replace('principals/', '', $principalUri)))
                ->get();

            foreach ($links as $link) {
                $this->external[$link->calendar_id] = $link;
            }
        }

        foreach ($calendars as $calendar) {
            $this->external[(int) $calendar['id'][0]] ??= false;
        }
    }

    /**
     * ctag and sync token of an external calendar come from the remote, not
     * from the local row. The remote answer is kept for the external cache
     * TTL; when the remote is down the last known values are served, so the
     * calendar never drops out of the home listing (a client would delete
     * its copy). Requests into the collection then get a 503.
     *
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

        $state = $this->remoteState($link);
        $syncToken = $state['sync_token'] !== null ? $this->encodeSyncToken($state['sync_token']) : null;

        if ($syncToken !== null) {
            $calendar['{http://sabredav.org/ns}sync-token'] = $syncToken;
        }

        $ctag = $state['ctag'] ?? ($syncToken !== null ? 'http://sabre.io/ns/sync/'.$syncToken : null);
        if ($ctag !== null) {
            $calendar['{'.Plugin::NS_CALENDARSERVER.'}getctag'] = $ctag;
        }

        return $calendar;
    }

    /**
     * @return array{ctag: ?string, sync_token: ?string}
     */
    private function remoteState(ExternalCalendar $link): array
    {
        $cache = app(UserCacheService::class);
        $key = [self::CACHE_KEY_STATE, $link->user_id, $link->calendar_id];
        $cached = $cache->get($key);

        if (is_array($cached) && $cached['fetched_at'] > time() - (int) config('calendars.external.cache_ttl')) {
            return $cached['state'];
        }

        try {
            $state = $this->remote()->collectionState($link);
        } catch (ExternalCalendarUnavailableException) {
            return is_array($cached) ? $cached['state'] : ['ctag' => null, 'sync_token' => null];
        }

        $cache->store($key, 0, ['fetched_at' => time(), 'state' => $state]);

        return $state;
    }

    private function markStateStale(ExternalCalendar $link): void
    {
        $cache = app(UserCacheService::class);
        $key = [self::CACHE_KEY_STATE, $link->user_id, $link->calendar_id];
        $cached = $cache->get($key);

        if (is_array($cached)) {
            $cache->store($key, 0, ['fetched_at' => 0, 'state' => $cached['state']]);
        }
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
        return [
            'id' => $this->externalObjectId($link, $object['uri']),
            'uri' => $object['uri'],
            'etag' => $object['etag'],
            'size' => $object['size'],
            'lastmodified' => null,
            'calendarid' => [$link->calendar_id, $link->instance_id],
        ] + (isset($object['calendardata']) ? ['calendardata' => $object['calendardata']] : []);
    }

    /**
     * External events have no calendarobjects row, so their id (used to key
     * event attachments) is derived from the link and the object name: stable
     * while the remote keeps the file name, and in [2^52, 2^53) so it never
     * meets a native autoincrement id and stays exact as a JSON number.
     */
    private function externalObjectId(ExternalCalendar $link, string $uri): int
    {
        return (1 << 52) | (int) hexdec(substr(hash('sha256', $link->id.'/'.$uri), 0, 13));
    }

    private function remote(): RemoteCalDavClient
    {
        return app(RemoteCalDavClient::class);
    }

    /**
     * The original slot (RECURRENCE-ID) of the occurrence a client means by
     * $occurrenceDate, in this order: an exception's RECURRENCE-ID, a regular
     * occurrence of the series, an exception moved to that time (older
     * clients sent the moved time), else $occurrenceDate itself.
     */
    private function resolveOccurrenceSlot(\Sabre\VObject\Component\VCalendar $vCal, \Sabre\VObject\Component\VEvent $masterEvent, \DateTimeInterface $occurrenceDate, bool $isAllDay): \DateTimeInterface
    {
        $matches = static fn (\DateTimeInterface $date): bool => $isAllDay
            ? $date->format('Ymd') === $occurrenceDate->format('Ymd')
            : $date->getTimestamp() === $occurrenceDate->getTimestamp();

        $exception = $this->findException($vCal, $occurrenceDate, $isAllDay);
        if ($exception) {
            return $exception->{'RECURRENCE-ID'}->getDateTime();
        }

        $series = new \Sabre\VObject\Recur\EventIterator([$masterEvent]);
        $series->fastForward(\DateTime::createFromInterface($occurrenceDate)->modify('-1 day'));
        while ($series->valid() && $series->getDtStart() <= \DateTime::createFromInterface($occurrenceDate)->modify('+1 day')) {
            if ($matches($series->getDtStart())) {
                return $occurrenceDate;
            }
            $series->next();
        }

        foreach ($vCal->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->DTSTART) && $matches($vevent->DTSTART->getDateTime())) {
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
            $recurrenceId = $vevent->{'RECURRENCE-ID'}->getDateTime();
            $same = $isAllDay
                ? $recurrenceId->format('Ymd') === $slot->format('Ymd')
                : $recurrenceId->getTimestamp() === $slot->getTimestamp();
            if ($same) {
                return $vevent;
            }
        }

        return null;
    }
}
