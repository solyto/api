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
    public function listCalendarsCustom(string $principalUri): array
    {
        $entries = parent::getCalendarsForUser($principalUri);

        $calendars = [];

        foreach ($entries as $entry) {
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

    public function updateCalendarCustom(CalendarDTO $dto): ?string
    {
        return parent::updateCalendar();
    }

    public function deleteCalendarCustom(CalendarDTO $calendar): void
    {
        parent::deleteCalendar([$calendar->calendarId, $calendar->instanceId]);
    }

    public function getCalendarInvitesCustom(string $principalUri): array
    {
        $invites = parent::getInvites($principalUri);
    }

    public function getEventsCustom(CalendarDTO $calendar): array
    {
        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, etag, calendarid, size, calendardata, componenttype FROM '.$this->calendarObjectTableName.' WHERE calendarid = ?');
        $stmt->execute([$calendar->calendarId]);

        $events = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $events[] = EventDTO::fromSabre($row, $calendar);
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

            foreach ($vObj->VEVENT as $vevent) {
                $startOrig = $vevent->DTSTART->getDateTime();
                $endOrig   = isset($vevent->DTEND) ? $vevent->DTEND->getDateTime() : $startOrig;
                $isAllDay = $vevent->DTSTART->getValueType() === 'DATE';

                if (isset($vevent->RRULE)) {
                    $rrule = (string) $vevent->RRULE;
                    $rruleEnd = isset($vevent->RRULE['UNTIL']) ? $vevent->RRULE['UNTIL']->getDateTime() : null;

                    $iterator = new \Sabre\VObject\Recur\EventIterator($vObj, (string)$vevent->UID);
                    $iterator->fastForward(new \DateTimeImmutable($start->format('Y-m-d H:i:s'), $start->getTimezone()));

                    while ($iterator->valid() && $iterator->getDtStart() < $end) {
                        $occStart = clone $iterator->getDtStart();
                        $occEnd = $iterator->getDtEnd() ? clone $iterator->getDtEnd() : null;

                        // Skip occurrences outside requested range
                        if ($occEnd && $occEnd < $start) {
                            $iterator->next();
                            continue;
                        }
                        if ($occStart > $end) {
                            break;
                        }

                        $occurrence = $iterator->getEventObject();

                        $eventDto = EventDTO::fromSabre([
                            'id' => $obj['id'],
                            'uri' => $uri,
                            'etag' => $obj['etag'],
                            'calendardata' => $occurrence->serialize(),
                        ], $calendar);

                        $eventDto->originalStartDate = clone $occStart;
                        $eventDto->recurrenceRule = $rrule;
                        $eventDto->recurrenceEnd = $rruleEnd;

                        $events[] = $eventDto;

                        $iterator->next();
                    }
                } else if (!isset($vevent->{'RECURRENCE-ID'})) {
                    // Skip single-instance events outside the requested range
                    if ($endOrig < $start || $startOrig > $end) {
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
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return EventDTO::fromSabre($row, $calendar);
    }

    public function createEventCustom(CalendarDTO $calendar, EventDTO $dto): ?string
    {
        $uri = bin2hex(Str::random(16)) . '.ics';

        return parent::createCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri, $dto->toVCal()) ? $uri : null;
    }

    public function importEventCustom(CalendarDTO $calendar, string $vCal): ?string
    {
        $uri = bin2hex(Str::random(16)) . '.ics';

        return parent::createCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri, $vCal) ? $uri : null;
    }

    public function updateEventCustom(CalendarDTO $calendar, EventDTO $dto): bool
    {
        return parent::updateCalendarObject([$calendar->calendarId, $calendar->instanceId], $dto->uri, $dto->toVCal()) !== null;
    }

    public function deleteEventCustom(CalendarDTO $calendar, EventDTO $dto): bool
    {
        parent::deleteCalendarObject([$calendar->calendarId, $calendar->instanceId], $dto->uri);

        return $this->getEventByUriCustom($calendar, $dto->uri) === null;
    }

    public function shareCalendarWithUserCustom(
        User $sender,
        User $receiver,
        CalendarDTO $calendar,
        bool $writeAccess
    )
    {
        parent::updateInvites([$calendar->calendarId, $calendar->instanceId], [
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
     * Delete a single occurrence from a recurring event by adding EXDATE.
     * This does NOT modify the master event's other properties.
     */
    public function deleteOccurrenceCustom(CalendarDTO $calendar, string $uri, \DateTimeInterface $occurrenceDate): bool
    {
        $obj = $this->getCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri);

        if (!$obj) {
            return false;
        }

        $vCalData = is_resource($obj['calendardata'])
            ? stream_get_contents($obj['calendardata'])
            : $obj['calendardata'];

        if (!$vCalData) {
            return false;
        }

        $vCal = Reader::read($vCalData);
        $masterEvent = null;

        foreach ($vCal->VEVENT as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->RRULE)) {
                $masterEvent = $vevent;
                break;
            }
        }

        if (!$masterEvent) {
            return false;
        }

        $isAllDay = $masterEvent->DTSTART->getValueType() === 'DATE';
        $tz = $masterEvent->DTSTART->getDateTime()->getTimezone();

        $occInTz = \DateTime::createFromInterface($occurrenceDate)->setTimezone($tz);

        if ($isAllDay) {
            $exdateValue = $occInTz->format('Ymd');
            $masterEvent->add('EXDATE', $exdateValue, ['VALUE' => 'DATE']);
        } else {
            $exdateValue = $occInTz->format('Ymd\THis');
            $masterEvent->add('EXDATE', $exdateValue, ['TZID' => $tz->getName()]);
        }

        return parent::updateCalendarObject(
            [$calendar->calendarId, $calendar->instanceId],
            $uri,
            $vCal->serialize()
        ) !== null;
    }

    /**
     * Update a single occurrence of a recurring event by adding an exception VEVENT with RECURRENCE-ID.
     * This does NOT modify the master event.
     */
    public function updateOccurrenceCustom(
        CalendarDTO $calendar,
        string $uri,
        \DateTimeInterface $occurrenceDate,
        EventDTO $updatedData
    ): bool {
        $obj = $this->getCalendarObject([$calendar->calendarId, $calendar->instanceId], $uri);

        if (!$obj) {
            return false;
        }

        $vCalData = is_resource($obj['calendardata'])
            ? stream_get_contents($obj['calendardata'])
            : $obj['calendardata'];

        if (!$vCalData) {
            return false;
        }

        $vCal = Reader::read($vCalData);
        $masterEvent = null;
        $uid = null;

        foreach ($vCal->VEVENT as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->RRULE)) {
                $masterEvent = $vevent;
                $uid = (string) $vevent->UID;
                break;
            }
        }

        if (!$masterEvent || !$uid) {
            return false;
        }

        $isAllDay = $masterEvent->DTSTART->getValueType() === 'DATE';
        $tz = $masterEvent->DTSTART->getDateTime()->getTimezone();

        $occInTz = \DateTime::createFromInterface($occurrenceDate)->setTimezone($tz);

        $exceptionEvent = $vCal->createComponent('VEVENT');
        $exceptionEvent->UID = $uid;
        $exceptionEvent->DTSTAMP = gmdate('Ymd\THis\Z');
        $exceptionEvent->SUMMARY = $updatedData->title;

        if ($isAllDay) {
            $exceptionEvent->add('RECURRENCE-ID', $occInTz->format('Ymd'), ['VALUE' => 'DATE']);
            $exceptionEvent->add('DTSTART', $updatedData->startDate->format('Ymd'), ['VALUE' => 'DATE']);
            if ($updatedData->endDate) {
                $end = (clone $updatedData->endDate)->modify('+1 day');
                $exceptionEvent->add('DTEND', $end->format('Ymd'), ['VALUE' => 'DATE']);
            }
        } else {
            $exceptionEvent->add('RECURRENCE-ID', $occInTz->format('Ymd\THis'), ['TZID' => $tz->getName()]);
            $exceptionEvent->add('DTSTART', $updatedData->startDate->format('Ymd\THis'), ['TZID' => $tz->getName()]);
            if ($updatedData->endDate) {
                $exceptionEvent->add('DTEND', $updatedData->endDate->format('Ymd\THis'), ['TZID' => $tz->getName()]);
            }
        }

        if ($updatedData->description) {
            $exceptionEvent->DESCRIPTION = $updatedData->description;
        }
        if ($updatedData->location) {
            $exceptionEvent->LOCATION = $updatedData->location;
        }

        $vCal->add($exceptionEvent);

        return parent::updateCalendarObject(
            [$calendar->calendarId, $calendar->instanceId],
            $uri,
            $vCal->serialize()
        ) !== null;
    }
}
