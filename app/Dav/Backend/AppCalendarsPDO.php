<?php

namespace App\Dav\Backend;

use App\Api\Users\Models\User;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Helpers\DavHelper;
use Illuminate\Support\Str;
use Sabre\CalDAV\Backend\PDO as SabreBackend;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Xml\Element\Sharee;
use Sabre\VObject\Reader;

class AppCalendarsPDO extends SabreBackend
{
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

        return array_values(array_filter(
            parent::getCalendarsForUser($principalUri),
            fn (array $calendar) => !in_array($calendar['id'][1], $notAccepted, true)
        ));
    }

    public function listCalendarsCustom(string $principalUri): array
    {
        $entries = parent::getCalendarsForUser($principalUri);

        $stmt = $this->pdo->prepare(
            'SELECT ci.share_invitestatus, ci.share_href, o.principaluri AS owner_principaluri
             FROM ' . $this->calendarInstancesTableName . ' ci
             LEFT JOIN ' . $this->calendarInstancesTableName . ' o ON o.calendarid = ci.calendarid AND o.access = 1
             WHERE ci.id = ?'
        );

        $calendars = [];

        foreach ($entries as $entry) {
            if ((int) ($entry['share-access'] ?? 0) > 1) {
                $stmt->execute([$entry['id'][1]]);
                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $entry['share-invitestatus'] = (int) $row['share_invitestatus'];
                    $entry['share-href']         = $row['share_href'];
                    $email = str_replace('principals/', '', $row['owner_principaluri']);
                    $entry['owner-name'] = User::where('email', $email)->value('name');
                }
            }
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

        foreach ($this->propertyMap as $xmlName => $dbName) {
            $calendar[$xmlName] = $row[$dbName];
        }

        return $calendar ? CalendarDTO::fromSabre($calendar) : null;
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

        foreach ($this->propertyMap as $xmlName => $dbName) {
            $calendar[$xmlName] = $row[$dbName];
        }

        return $calendar ? CalendarDTO::fromSabre($calendar) : null;
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
        parent::deleteCalendar([$calendar->calendarId, $calendar->instanceId]);
    }

    public function getCalendarInvitesCustom(string $principalUri): array
    {
        return parent::getInvites($principalUri);
    }

    public function getEventsCustom(CalendarDTO $calendar): array
    {
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

    public function getExpandedEventsCustom(CalendarDTO $calendar, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $filters = [
            'name' => 'VEVENT',
            'prop-filters' => [],
            'comp-filters' => [],
            'time-range' => [
                'start' => $start,
                'end'   => $end,
            ],
        ];

        $uris = parent::calendarQuery(
            [$calendar->calendarId, $calendar->instanceId],
            $filters
        );

        $events = [];

        foreach ($uris as $uri) {
            $obj = $this->getCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri);

            $vCalData = is_resource($obj['calendardata'])
                ? stream_get_contents($obj['calendardata'])
                : $obj['calendardata'];

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
                            'uri' => $uri,
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
                    // An event ending exactly at the window start does not
                    // overlap it (exclusive end, RFC 4791/5545).
                    if ($endOrig <= $start || $startOrig > $end) {
                        continue;
                    }

                    $events[] = EventDTO::fromSabre([
                        'id' => $obj['id'],
                        'uri' => $uri,
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
        return parent::createCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri, $dto->toVCalForUpdate()) ? $uri : null;
    }

    public function importEventCustom(CalendarDTO $calendar, string $vCal): ?string
    {
        $uri = bin2hex(Str::random(16)) . '.ics';

        return parent::createCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri, $vCal) ? $uri : null;
    }

    public function updateEventCustom(CalendarDTO $calendar, EventDTO $dto): bool
    {
        return parent::updateCalendarObject([$calendar->calendarId, $calendar->instanceId], $dto->uri, $dto->toVCalForUpdate()) !== null;
    }

    public function deleteEventCustom(CalendarDTO $calendar, EventDTO $dto): bool
    {
        parent::deleteCalendarObject([$calendar->calendarId, $calendar->instanceId], $dto->uri);

        return $this->getEventByUriCustom($calendar, $dto->uri) === null;
    }

    public function updateInvites($calendarId, array $sharees): void
    {
        if (!is_array($calendarId)) {
            throw new \InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
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

    public function acceptShare(string $shareToken): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->calendarInstancesTableName . ' SET share_invitestatus = 2 WHERE share_href = ?'
        );
        $stmt->execute([$shareToken]);
    }

    public function declineShare(string $shareToken): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE share_href = ?'
        );
        $stmt->execute([$shareToken]);
    }

    public function unshareCalendar(int $calendarId, string $recipientEmail): void
    {
        $principalUri = 'principals/' . $recipientEmail;
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE calendarid = ? AND principaluri = ? AND access > 1'
        );
        $stmt->execute([$calendarId, $principalUri]);
    }

    public function deleteSharedInstancesCustom(string $principalUri): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->calendarInstancesTableName . ' WHERE principaluri = ? AND access > 1'
        );
        $stmt->execute([$principalUri]);

        return $stmt->rowCount();
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
        [$vCal, $masterEvent] = $this->readRecurringObject($calendar, $uri);

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

        return parent::updateCalendarObject(
            [$calendar->calendarId, $calendar->instanceId],
            $uri,
            $vCal->serialize()
        ) !== null;
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
        [$vCal, $masterEvent] = $this->readRecurringObject($calendar, $uri);

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

        return parent::updateCalendarObject(
            [$calendar->calendarId, $calendar->instanceId],
            $uri,
            $vCal->serialize()
        ) !== null;
    }

    /**
     * @return array{0: ?\Sabre\VObject\Component\VCalendar, 1: ?\Sabre\VObject\Component\VEvent}
     */
    private function readRecurringObject(CalendarDTO $calendar, string $uri): array
    {
        $obj = $this->getCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri);

        if (!$obj) {
            return [null, null];
        }

        $vCalData = is_resource($obj['calendardata'])
            ? stream_get_contents($obj['calendardata'])
            : $obj['calendardata'];

        if (!$vCalData) {
            return [null, null];
        }

        $vCal = Reader::read($vCalData);

        foreach ($vCal->VEVENT ?? [] as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->RRULE)) {
                return [$vCal, $vevent];
            }
        }

        return [$vCal, null];
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
