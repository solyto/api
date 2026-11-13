<?php

namespace App\Dav\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Sabre\VObject\Reader;

class ICalendarHelper
{
    public function parseEntriesFromXml($responseBody, $calendarName): array
    {
        $events = [];

        try {
            $dom = new \DOMDocument;
            if (! @$dom->loadXML($responseBody)) {
                Log::error('Failed to parse calendar response XML', [
                    'body' => $responseBody,
                    'libxml_errors' => libxml_get_errors(),
                    'calendar' => $calendarName,
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
            Log::error('Error parsing calendar response: '.$e->getMessage());
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
            Log::error('Error parsing iCal data: '.$e->getMessage());
        }

        return $events;
    }

    private function parseDateTime($dateTime): ?Carbon
    {
        if (! $dateTime) {
            return null;
        }

        try {
            return Carbon::parse($dateTime->getDateTime());
        } catch (\Exception $e) {
            Log::error('Error parsing date: '.$e->getMessage());

            return null;
        }
    }

    private function isAllDayEvent($dtStart): bool
    {
        return $dtStart && ! $dtStart->hasTime();
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
