<?php

namespace App\Api\Export\Services;

use App\Api\Users\Models\User;
use App\Dav\Services\DavService;

class CalendarExportService
{
    public function __construct(
        private readonly DavService $davService
    ) {}

    public function export(User $user, string $path): void
    {
        $calendars = $this->davService->calendars()->list($user);

        $header = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Solyto//Export//EN\r\nCALSCALE:GREGORIAN\r\n";
        $timezones = '';
        $events = '';
        $addedTimezones = [];

        foreach ($calendars as $calendar) {
            foreach ($this->davService->calendars()->events()->list($calendar) as $event) {
                $vcal = $event->toVCal();

                if (preg_match_all('/BEGIN:VTIMEZONE.*?END:VTIMEZONE/s', $vcal, $tzMatches)) {
                    foreach ($tzMatches[0] as $tzBlock) {
                        if (preg_match('/TZID:(.+)/', $tzBlock, $m)) {
                            $tzId = trim($m[1]);
                            if (!isset($addedTimezones[$tzId])) {
                                $addedTimezones[$tzId] = true;
                                $timezones .= $tzBlock."\r\n";
                            }
                        }
                    }
                }

                if (preg_match('/BEGIN:VEVENT.*?END:VEVENT/s', $vcal, $veventMatch)) {
                    $events .= $veventMatch[0]."\r\n";
                }
            }
        }

        file_put_contents($path, $header.$timezones.$events."END:VCALENDAR\r\n");
    }
}
