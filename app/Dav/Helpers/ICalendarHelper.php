<?php

namespace App\Dav\Helpers;

use App\Models\NextcloudCalendarEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Sabre\VObject\Reader;


class ICalendarHelper
{

    public function buildICalendarData(NextcloudCalendarEntry $entry): string
    {
        $uid = $entry->uid ?? $entry->id;
        $created = $entry->created_at ? $entry->created_at->utc()->format('Ymd\THis\Z') : Carbon::now()->utc()->format('Ymd\THis\Z');
        $modified = $entry->updated_at ? $entry->updated_at->utc()->format('Ymd\THis\Z') : Carbon::now()->utc()->format('Ymd\THis\Z');

        // Format dates
        if ($entry->is_all_day) {
            $startDate = Carbon::parse($entry->start_date)->format('Ymd');
            $endDate = Carbon::parse($entry->end_date)->format('Ymd');
            $dateFormat = 'VALUE=DATE';
        } else {
            $startDate = Carbon::parse($entry->start_date)->utc()->format('Ymd\THis\Z');
            $endDate = Carbon::parse($entry->end_date)->utc()->format('Ymd\THis\Z');
            $dateFormat = '';
        }

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//SOLYTO//SOLYTO//EN\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTART" . ($dateFormat ? ";{$dateFormat}" : '') . ":{$startDate}\r\n";
        $ical .= "DTEND" . ($dateFormat ? ";{$dateFormat}" : '') . ":{$endDate}\r\n";
        $ical .= "SUMMARY:" . ($entry->title ?? '') . "\r\n";

        if ($entry->description) {
            $ical .= "DESCRIPTION:" . ($entry->description ?? '') . "\r\n";
        }

        if ($entry->location) {
            $ical .= "LOCATION:" . ($entry->location ?? '') . "\r\n";
        }

        if ($entry->is_recurring && $entry->recurrence_rule) {
            $ical .= "RRULE:" . $entry->recurrence_rule . "\r\n";
        }

        $ical .= "CREATED:{$created}\r\n";
        $ical .= "LAST-MODIFIED:{$modified}\r\n";
        $ical .= "DTSTAMP:" . Carbon::now()->utc()->format('Ymd\THis\Z') . "\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    public function generateEventFilename(NextcloudCalendarEntry $entry): string
    {
        $uid = $entry->uid ?? $entry->id;
        return $uid . '.ics';
    }

    public function parseCalendarsFromXml(string $responseBody, string $baseUrl): array
    {
        $calendars = [];

        try {
            $dom = new \DOMDocument();

            if (!@$dom->loadXML($responseBody)) {
                Log::error('Failed to parse calendars XML', [
                    'body' => $responseBody,
                    'libxml_errors' => libxml_get_errors()
                ]);
                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $href = $xpath->query('.//d:href', $response)->item(0);
                $resourceType = $xpath->query('.//d:resourcetype/c:calendar', $response);

                if ($resourceType->length > 0 && $href) {
                    $displayName = $xpath->query('.//d:displayname', $response)->item(0);

                    $color = '#e5e7eb';
                    $colorNode = $xpath->query('.//*[local-name()="calendar-color"]', $response)->item(0);
                    if ($colorNode) {
                        $color = $colorNode->textContent;
                    }

                    $calendars[] = [
                        'url' => UrlHelper::getBaseUrl($baseUrl) . $href->textContent,
                        'name' => $displayName ? $displayName->textContent : 'Unnamed Calendar',
                        'color' => $color
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('Error parsing calendars XML: ' . $e->getMessage());
        }

        return $calendars;
    }

    public function parseEntriesFromXml($responseBody, $calendarName): array
    {
        $events = [];

        try {
            $dom = new \DOMDocument();
            if (!@$dom->loadXML($responseBody)) {
                Log::error('Failed to parse calendar response XML', [
                    'body' => $responseBody,
                    'libxml_errors' => libxml_get_errors(),
                    'calendar' => $calendarName
                ]);
                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                // Extract ETag and URL/href
                $etag = $xpath->query('.//d:getetag', $response)->item(0);
                $href = $xpath->query('.//d:href', $response)->item(0);
                $calendarData = $xpath->query('.//c:calendar-data', $response)->item(0);

                if ($calendarData) {
                    $icalData = $calendarData->textContent;
                    $parsedEvents = $this->parseICalData($icalData, $calendarName, $etag, $href);
                    $events = array_merge($events, $parsedEvents);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error parsing calendar response: ' . $e->getMessage());
        }

        return $events;
    }

    private function parseICalData($icalData, $calendarName, $etag, $href): array
    {
        $events = [];

        try {
            $vObject = Reader::read($icalData);

            foreach ($vObject->VEVENT as $vevent) {
                $hasRRule = isset($vevent->RRULE);
                $isExpandedInstance = isset($vevent->{'RECURRENCE-ID'});

                // If it has RRULE, it's the master recurring event
                // If it has RECURRENCE-ID, it's an expanded instance of a recurring event
                $isRecurring = $hasRRule || $isExpandedInstance;

                $recurrenceRule = null;
                $recurrenceEnd = null;

                if ($hasRRule) {
                    $recurrenceRule = (string) $vevent->RRULE;

                    // Check if RRULE has an UNTIL date
                    if (isset($vevent->RRULE['UNTIL'])) {
                        $recurrenceEnd = $this->parseDateTime($vevent->RRULE['UNTIL']);
                    }
                }

                $isAllDay = $this->isAllDayEvent($vevent->DTSTART);
                $endDate = $this->parseDateTime($vevent->DTEND);

                if ($isAllDay && $endDate) {
                    $endDate->subDay();
                }

                $event = [
                    'calendar_name' => $calendarName,
                    'uid' => (string) $vevent->UID,
                    'title' => (string) $vevent->SUMMARY,
                    'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : '',
                    'location' => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : '',
                    'start' => $this->parseDateTime($vevent->DTSTART),
                    'end' => $endDate,
                    'all_day' => $isAllDay,
                    'is_recurring' => $isRecurring,
                    'recurrence_rule' => $recurrenceRule,
                    'recurrence_end' => $recurrenceEnd,
                    'created' => isset($vevent->CREATED) ? $this->parseDateTime($vevent->CREATED) : null,
                    'modified' => isset($vevent->{'LAST-MODIFIED'}) ? $this->parseDateTime($vevent->{'LAST-MODIFIED'}) : null,
                    'etag' => $etag ? trim($etag->textContent, '"') : null,
                    'url' => $href ? $href->textContent : null,
                ];

                $events[] = $event;
            }
        } catch (\Exception $e) {
            Log::error('Error parsing iCal data: ' . $e->getMessage());
        }

        return $events;
    }

    private function parseDateTime($dateTime): ?Carbon
    {
        if (!$dateTime) return null;

        try {
            return Carbon::parse($dateTime->getDateTime());
        } catch (\Exception $e) {
            Log::error('Error parsing date: ' . $e->getMessage());
            return null;
        }
    }

    private function isAllDayEvent($dtStart): bool
    {
        return $dtStart && !$dtStart->hasTime();
    }

    public function parseCalendarHomeFromXml(string $xml, string $baseUrl): array
    {
        try {
            $dom = new \DOMDocument();

            if (!@$dom->loadXML($xml)) {
                Log::error('Failed to parse calendar home XML', ['body' => $xml]);
                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');

            $nodes = $xpath->query('//cal:calendar-home-set/d:href');

            $homes = [];
            foreach ($nodes as $node) {
                $homes[] = [
                    'url' => rtrim(UrlHelper::getBaseUrl($baseUrl), '/') . $node->textContent
                ];
            }

            return $homes;
        } catch (\Exception $e) {
            Log::error('Error parsing calendar home XML: ' . $e->getMessage());
            return [];
        }
    }


    public static function getCalendarsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <d:propfind
                xmlns:d="DAV:"
                xmlns:cal="urn:ietf:params:xml:ns:caldav"
                xmlns:ical="http://apple.com/ns/ical/">
                <d:prop>
                    <d:displayname />
                    <d:resourcetype />
                    <cal:supported-calendar-component-set />
                    <ical:calendar-color />
                </d:prop>
            </d:propfind>';
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
                            <c:time-range start="' . $start . '" end="' . $end . '"/>
                        </c:comp-filter>
                    </c:comp-filter>
                </c:filter>
            </c:calendar-query>';
    }

    public static function getCalendarHomeDiscoveryXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
            <d:prop>
                <cal:calendar-home-set />
            </d:prop>
        </d:propfind>';
    }
}
