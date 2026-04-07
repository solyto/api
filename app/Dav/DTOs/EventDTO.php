<?php

namespace App\Dav\DTOs;

use App\Api\Calendars\Requests\UpdateEventRequest;
use App\Api\Users\Models\User;
use Illuminate\Support\Str;

class EventDTO
{
    public function __construct(
        public ?int $id,
        public string $title,
        public ?string $description,
        public ?\DateTimeInterface $startDate,
        public ?\DateTimeInterface $endDate,
        public bool $isAllDay,
        public ?string $location,
        public ?string $recurrenceRule,
        public ?\DateTimeInterface $recurrenceEnd,
        public ?string $timezone,
        public ?string $uri,
        public ?string $etag,
        public ?string $calendarId,
        public ?string $calendarName,
        public ?string $calendarColor = null,
        public ?\DateTimeInterface $originalStartDate = null
    ) {}

    public static function fromSabre(array $data, CalendarDTO $calendar): self
    {
        $vCalData = $data['calendardata'] ?? '';
        if (is_resource($vCalData)) {
            $vCalData = stream_get_contents($vCalData);
        }

        $parsed = self::parseVCal($vCalData);

        return new self(
            id: $data['id'] ?? null,
            title: $parsed['title'] ?? '',
            description: $parsed['description'] ?? null,
            startDate: $parsed['start'] ?? null,
            endDate: $parsed['end'] ?? null,
            isAllDay: $parsed['is_all_day'] ?? false,
            location: $parsed['location'] ?? null,
            recurrenceRule: $parsed['recurrence_rule'] ?? null,
            recurrenceEnd: $parsed['recurrence_end'] ?? null,
            timezone: $parsed['timezone'] ?? null,
            uri: $data['uri'] ?? null,
            etag: $data['etag'] ?? null,
            calendarId: $calendar->instanceId,
            calendarName: $calendar->displayName,
            calendarColor: $calendar->color
        );
    }

    public static function fromRequest(array $data, CalendarDTO $calendar, User $user): self
    {
        $userTz = new \DateTimeZone($user->settings->timezone);
        $start = isset($data['start_date']) ? new \DateTime($data['start_date'], $userTz) : null;
        $end = isset($data['end_date']) ? new \DateTime($data['end_date'], $userTz) : null;
        $recEnd = isset($data['recurrence_end']) ? new \DateTime($data['recurrence_end'], $userTz) : null;

        return new self(
            id: 0,
            title: $data['title'],
            description: $data['description'] ?? null,
            startDate: $start,
            endDate: $end,
            isAllDay: $data['is_all_day'] ?? false,
            location: $data['location'] ?? null,
            recurrenceRule: $data['recurrence_rule'] ?? null,
            recurrenceEnd: $recEnd,
            timezone: $user->settings->timezone,
            uri: null,
            etag: $data['etag'] ?? null,
            calendarId: $calendar->instanceId,
            calendarName: $calendar->displayName,
            calendarColor: $calendar->color
        );
    }


    public static function fromImport(array $parsed, CalendarDTO $calendar): self
    {
        return new self(
            id: null,
            title: $parsed['title'] ?? '',
            description: $parsed['description'] ?? null,
            startDate: $parsed['start'] ?? null,
            endDate: $parsed['end'] ?? null,
            isAllDay: $parsed['all_day'] ?? false,
            location: $parsed['location'] ?? null,
            recurrenceRule: $parsed['recurrence_rule'] ?? null,
            recurrenceEnd: $parsed['recurrence_end'] ?? null,
            timezone: $parsed['timezone'] ?? null,
            uri: null,
            etag: null,
            calendarId: $calendar->instanceId,
            calendarName: $calendar->displayName,
            calendarColor: $calendar->color
        );
    }

    public function updateFromRequest(UpdateEventRequest $request, User $user): void
    {
        $data = $request->validated();
        $userTz = new \DateTimeZone($user->settings->timezone);

        $this->title = $data['title'];
        $this->description = $data['description'] ?? $this->description;

        if (isset($data['start_date'])) {
            $this->startDate = new \DateTime(
                $data['start_date'],
                $userTz
            );
        }

        if (isset($data['end_date'])) {
            $this->endDate = new \DateTime(
                $data['end_date'],
                $userTz
            );
        }

        if (isset($data['recurrence_end'])) {
            $this->recurrenceEnd = new \DateTime(
                $data['recurrence_end'],
                $userTz
            );
        }

        $this->isAllDay = $data['is_all_day'] ?? $this->isAllDay;
        $this->location = $data['location'] ?? $this->location;
        $this->recurrenceRule = $data['recurrence_rule'] ?? $this->recurrenceRule;

        $this->timezone = $user->settings->timezone; // always use user’s timezone
        $this->etag = $data['etag'] ?? $this->etag;
    }


    public static function parseVCal(string $vCalData): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $vCalData));
        $data = [
            'title' => null,
            'description' => null,
            'start' => null,
            'end' => null,
            'is_all_day' => false,
            'location' => null,
            'recurrence_rule' => null,
            'recurrence_end' => null,
            'timezone' => null,
            'uri' => null,
        ];

        $currentTz = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // TZID from VTIMEZONE block or DTSTART/DTEND
            if (preg_match('/^TZID:(.+)$/', $line, $matches)) {
                $currentTz = $matches[1];
                $data['timezone'] = $currentTz;
                continue;
            }

            if (str_starts_with($line, 'SUMMARY:')) {
                $data['title'] = substr($line, 8);
            } elseif (str_starts_with($line, 'DESCRIPTION:')) {
                $data['description'] = substr($line, 12);
            } elseif (str_starts_with($line, 'LOCATION:')) {
                $data['location'] = substr($line, 9);
            } elseif (str_starts_with($line, 'RRULE:')) {
                $data['recurrence_rule'] = substr($line, 6);
            } elseif (str_starts_with($line, 'DTSTART')) {
                $isAllDay = str_contains($line, 'VALUE=DATE');
                $dateStr = substr($line, strpos($line, ':') + 1);

                if (preg_match('/TZID=([^:]+):/', $line, $matches)) {
                    $tz = new \DateTimeZone($matches[1]);
                    $data['timezone'] = $matches[1];
                } elseif ($currentTz) {
                    $tz = new \DateTimeZone($currentTz);
                } else {
                    $tz = new \DateTimeZone('UTC');
                }

                // Parse date
                $dt = null;
                if (str_ends_with($dateStr, 'Z')) {
                    $dt = new \DateTime($dateStr); // UTC
                } elseif ($isAllDay) {
                    $dt = new \DateTime($dateStr, $tz);
                } else {
                    $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, $tz);
                }

                $data['start'] = $dt;

                // Fallback for full-day events without VALUE=DATE
                if (!$isAllDay && $dt->format('His') === '000000') {
                    $isAllDay = true;
                }

                $data['is_all_day'] = $isAllDay;
            } elseif (str_starts_with($line, 'DTEND')) {
                $isAllDay = str_contains($line, 'VALUE=DATE');
                $dateStr = substr($line, strpos($line, ':') + 1);

                if (preg_match('/TZID=([^:]+):/', $line, $matches)) {
                    $tz = new \DateTimeZone($matches[1]);
                    $data['timezone'] = $matches[1];
                } elseif ($currentTz) {
                    $tz = new \DateTimeZone($currentTz);
                } else {
                    $tz = new \DateTimeZone('UTC');
                }

                $dt = null;
                if (str_ends_with($dateStr, 'Z')) {
                    $dt = new \DateTime($dateStr); // UTC
                } elseif ($isAllDay) {
                    $dt = new \DateTime($dateStr, $tz);
                } else {
                    $dt = \DateTime::createFromFormat('Ymd\THis', $dateStr, $tz);
                }

                // Full-day fallback if start is midnight
                if (!$isAllDay && $dt && $data['start'] && $data['start']->format('His') === '000000' && $dt->format('His') === '000000') {
                    $isAllDay = true;
                }

                if ($isAllDay && $dt) {
                    $dt->modify('-1 day');
                }

                $data['end'] = $dt;
                $data['is_all_day'] = $isAllDay;
            }
        }

        return $data;
    }



    public function toVCal(): string
    {
        $escape = static fn(string $value): string => str_replace(
            ['\\', ';', ',', "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', ''],
            $value
        );

        $foldLine = static function(string $line): string {
            if (strlen($line) <= 75) {
                return $line;
            }
            $result = '';
            while (strlen($line) > 75) {
                if ($result === '') {
                    $result = substr($line, 0, 75);
                    $line = substr($line, 75);
                } else {
                    $result .= "\r\n " . substr($line, 0, 74);
                    $line = substr($line, 74);
                }
            }
            if ($line !== '') {
                $result .= "\r\n " . $line;
            }
            return $result;
        };

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Solyto//Solyto//EN',
            'CALSCALE:GREGORIAN',
        ];

        // Add VTIMEZONE if this is a timed event
        if ($this->startDate && !$this->isAllDay) {
            $lines[] = $this->generateVTimeZone($this->startDate->getTimezone(), $this->startDate);
        }

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . Str::uuid()->toString();
        $lines[] = 'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z');
        $lines[] = $foldLine('SUMMARY:' . $escape($this->title));

        if ($this->description) $lines[] = $foldLine('DESCRIPTION:' . $escape($this->description));
        if ($this->location) $lines[] = $foldLine('LOCATION:' . $escape($this->location));

        if ($this->startDate) {
            if ($this->isAllDay) {
                $lines[] = 'DTSTART;VALUE=DATE:' . $this->startDate->format('Ymd');
            } else {
                $tzName = $this->startDate->getTimezone()->getName();
                $lines[] = 'DTSTART;TZID=' . $tzName . ':' . $this->startDate->format('Ymd\THis');
            }
        }

        if ($this->endDate) {
            if ($this->isAllDay) {
                $end = (clone $this->endDate)->modify('+1 day'); // exclusive
                $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
            } else {
                $tzName = $this->endDate->getTimezone()->getName();
                $lines[] = 'DTEND;TZID=' . $tzName . ':' . $this->endDate->format('Ymd\THis');
            }
        }

        if ($this->recurrenceRule) $lines[] = 'RRULE:' . $this->recurrenceRule;

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Generate DST-aware VTIMEZONE for any timezone and any event date
     */
    protected function generateVTimeZone(\DateTimeZone $tz, \DateTimeInterface $eventDate): string
    {
        $tzName = $tz->getName();
        $year = (int)$eventDate->format('Y');

        // Get transitions around event year ±1
        $transitions = $tz->getTransitions(strtotime("$year-01-01"), strtotime(($year + 1) . "-12-31"));

        $lines = ["BEGIN:VTIMEZONE", "TZID:$tzName"];
        $added = [];

        foreach ($transitions as $tr) {
            $isDst = $tr['isdst'];
            $offsetFrom = $this->formatOffset($tr['offset'] - ($isDst ? 3600 : 0));
            $offsetTo   = $this->formatOffset($tr['offset']);
            $type = $isDst ? 'DAYLIGHT' : 'STANDARD';

            $key = $type . $offsetFrom . $offsetTo;
            if (isset($added[$key])) continue; // avoid duplicates
            $added[$key] = true;

            $dt = new \DateTime($tr['time'], $tz);

            $lines[] = "BEGIN:$type";
            $lines[] = "DTSTART:" . $dt->format('Ymd\THis');
            $lines[] = "TZOFFSETFROM:$offsetFrom";
            $lines[] = "TZOFFSETTO:$offsetTo";
            $lines[] = "TZNAME:$type";
            $lines[] = "END:$type";
        }

        $lines[] = "END:VTIMEZONE";

        return implode("\r\n", $lines);
    }

    /**
     * Convert offset seconds to +HHMM/-HHMM
     */
    protected function formatOffset(int $offsetSeconds): string
    {
        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $offsetSeconds = abs($offsetSeconds);
        $hours = floor($offsetSeconds / 3600);
        $minutes = floor(($offsetSeconds % 3600) / 60);
        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }

}
