<?php

namespace App\Api\Export\Services;

use App\Api\Users\Models\User;
use App\Dav\DTOs\EventDTO;
use App\Dav\Services\DavService;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class CalendarExportService
{
    public function __construct(
        private readonly DavService $davService
    ) {}

    /**
     * Writes one iCalendar file with every calendar's objects as they are
     * stored (UIDs, exceptions, alarms, attendees, tasks), each time zone once.
     */
    public function export(User $user, string $path): void
    {
        // Like the calendar view: calendars from unanswered invites are not the user's yet.
        $calendars = array_filter($this->davService->calendars()->list($user), fn ($calendar) => $calendar->inviteStatus !== 'pending');

        $header = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Solyto//Export//EN\r\nCALSCALE:GREGORIAN\r\n";
        $timezones = '';
        $components = '';
        $addedTimezones = [];
        $usedUids = [];

        foreach ($calendars as $calendar) {
            foreach ($this->davService->calendars()->events()->list($calendar) as $event) {
                $vcalendar = $this->readStored($event);

                foreach ($vcalendar->select('VTIMEZONE') as $vtimezone) {
                    $tzId = (string) $vtimezone->TZID;
                    if (!isset($addedTimezones[$tzId])) {
                        $addedTimezones[$tzId] = true;
                        $timezones .= $vtimezone->serialize();
                    }
                }

                // Two objects with one UID would overwrite each other on import.
                $objectUids = [];
                foreach ([...$vcalendar->select('VEVENT'), ...$vcalendar->select('VTODO')] as $component) {
                    $uid = (string) $component->UID;
                    if (isset($usedUids[$uid])) {
                        $component->UID = $uid.'-'.$calendar->instanceId.'-'.$event->id;
                    }
                    $objectUids[$uid] = true;
                    $components .= $component->serialize();
                }
                $usedUids += $objectUids;
            }
        }

        file_put_contents($path, $header.$timezones.$components."END:VCALENDAR\r\n");
    }

    /**
     * The stored object, or one rebuilt from the event when there is nothing
     * readable stored.
     */
    private function readStored(EventDTO $event): VCalendar
    {
        try {
            $vcalendar = $event->storedData ? Reader::read($event->storedData) : null;
            if ($vcalendar instanceof VCalendar) {
                return $vcalendar;
            }
        } catch (\Throwable) {
            // Unreadable stored data: rebuilt from the event below.
        }

        /** @var VCalendar */
        return Reader::read($event->toVCal());
    }
}
