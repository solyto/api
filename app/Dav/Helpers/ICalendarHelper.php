<?php

namespace App\Dav\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ICalendarHelper
{
    // The raw calendar-data of each response in a calendar-query REPORT; an unreadable body yields none.
    /** @return array<int, string> */
    public function calendarDataFromXml(string $responseBody): array
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($responseBody, LIBXML_NONET)) {
            Log::channel('dav')->warning('Calendar import could not parse a calendar-query response');

            return [];
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $data = [];

        foreach ($xpath->query('//d:response//c:calendar-data') as $calendarData) {
            $data[] = $calendarData->textContent;
        }

        return $data;
    }

    public static function getEntriesXml(): string
    {
        $start = Carbon::now()->subYears(10)->startOfDay()->format('Ymd\THis\Z');
        $end = Carbon::now()->addYears(10)->endOfDay()->format('Ymd\THis\Z');

        return '<?xml version="1.0" encoding="UTF-8"?>
            <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
                <d:prop>
                    <d:getetag />
                    <c:calendar-data />
                </d:prop>
                <c:filter>
                    <c:comp-filter name="VCALENDAR">
                        <c:comp-filter name="VEVENT">
                            <c:time-range start="'.$start.'" end="'.$end.'"/>
                        </c:comp-filter>
                    </c:comp-filter>
                </c:filter>
            </c:calendar-query>';
    }
}
