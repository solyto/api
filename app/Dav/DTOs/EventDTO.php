<?php

namespace App\Dav\DTOs;

use App\Api\Calendars\Requests\UpdateEventRequest;
use App\Api\Users\Models\User;
use Illuminate\Support\Str;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Sabre\VObject\TimeZoneUtil;

class EventDTO
{
    /**
     * The stored iCalendar object, set only when a single event is read for
     * an edit (AppCalendarsPDO::getEventByUriCustom()); listings and caches
     * never carry it.
     */
    public ?string $storedData = null;

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
        $seriesStart = $this->startDate;

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

        // A series edited through one of its occurrences: start_date is that
        // occurrence's new start, so the series moves by the same wall-clock
        // difference instead of starting at the occurrence.
        if (isset($data['occurrence_start_date']) && $seriesStart && $this->startDate && $this->endDate) {
            $wallClock = static fn (\DateTimeInterface $date): \DateTimeImmutable => new \DateTimeImmutable($date->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
            $occurrenceStart = new \DateTime($data['occurrence_start_date'], $userTz);
            $shift = $wallClock($this->startDate)->getTimestamp() - $wallClock($occurrenceStart)->getTimestamp();
            $duration = $wallClock($this->endDate)->getTimestamp() - $wallClock($this->startDate)->getTimestamp();
            // All-day dates are floating; converting them could change the day.
            $base = ($data['is_all_day'] ?? false) ? $seriesStart : \DateTime::createFromInterface($seriesStart)->setTimezone($userTz);
            $start = $wallClock($base)->modify("{$shift} seconds");

            $this->startDate = new \DateTime($start->format('Y-m-d H:i:s'), $userTz);
            $this->endDate = new \DateTime($start->modify("{$duration} seconds")->format('Y-m-d H:i:s'), $userTz);
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
        // Unfold continuation lines (RFC 5545 3.1) before reading properties.
        $lines = explode("\n", preg_replace("/\n[ \t]/", '', str_replace("\r\n", "\n", $vCalData)));
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

        foreach (self::selectEventLines($lines) as $line) {
            [$name, $params, $value] = self::splitContentLine(trim($line));

            // TZID from VTIMEZONE block or DTSTART/DTEND
            if ($name === 'TZID') {
                $currentTz = TimeZoneUtil::getTimeZone($value)->getName();
                $data['timezone'] = $currentTz;
                continue;
            }

            if ($name === 'SUMMARY') {
                $data['title'] = self::unescapeText($value);
            } elseif ($name === 'DESCRIPTION') {
                $data['description'] = self::unescapeText($value);
            } elseif ($name === 'LOCATION') {
                $data['location'] = self::unescapeText($value);
            } elseif ($name === 'RRULE') {
                $data['recurrence_rule'] = $value;
            } elseif ($name === 'DTSTART') {
                $isAllDay = str_contains($params, 'VALUE=DATE');
                $dateStr = $value;

                if (preg_match('/TZID="?([^";]+)"?/', $params, $matches)) {
                    $tz = TimeZoneUtil::getTimeZone($matches[1]);
                    $data['timezone'] = $tz->getName();
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
            } elseif ($name === 'DTEND') {
                $isAllDay = str_contains($params, 'VALUE=DATE');
                $dateStr = $value;

                if (preg_match('/TZID="?([^";]+)"?/', $params, $matches)) {
                    $tz = TimeZoneUtil::getTimeZone($matches[1]);
                    $data['timezone'] = $tz->getName();
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

    /**
     * Pick the lines to read: the TZID lines of the VTIMEZONE blocks and the
     * properties of the master VEVENT (the one without RECURRENCE-ID), leaving
     * out nested components such as VALARM. Data without a VEVENT is read as is.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private static function selectEventLines(array $lines): array
    {
        $tzidLines = [];
        $events = [];
        $current = null;
        $components = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (stripos($line, 'BEGIN:') === 0) {
                $components[] = strtoupper(substr($line, 6));
                if (end($components) === 'VEVENT') {
                    $current = [];
                }
                continue;
            }

            if (stripos($line, 'END:') === 0) {
                if (array_pop($components) === 'VEVENT' && $current !== null) {
                    $events[] = $current;
                    $current = null;
                }
                continue;
            }

            $component = end($components);
            if ($component === 'VEVENT' && $current !== null) {
                $current[] = $line;
            } elseif ($component === 'VTIMEZONE' && stripos($line, 'TZID:') === 0) {
                $tzidLines[] = $line;
            }
        }

        if ($events === []) {
            return $lines;
        }

        $isException = static fn (array $event): bool => collect($event)
            ->contains(fn (string $line) => stripos($line, 'RECURRENCE-ID') === 0);
        $master = collect($events)->first(fn (array $event) => !$isException($event)) ?? $events[0];

        return [...$tzidLines, ...$master];
    }

    /**
     * Split a content line into its upper-cased name, its raw parameters and
     * its value; the value starts at the first colon outside a quoted
     * parameter (TZID="W. Europe Standard Time").
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function splitContentLine(string $line): array
    {
        $inQuotes = false;

        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            if ($line[$i] === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($line[$i] === ':' && !$inQuotes) {
                $head = substr($line, 0, $i);
                $semicolon = strpos($head, ';');

                return [
                    strtoupper($semicolon === false ? $head : substr($head, 0, $semicolon)),
                    $semicolon === false ? '' : substr($head, $semicolon + 1),
                    substr($line, $i + 1),
                ];
            }
        }

        return [strtoupper($line), '', ''];
    }

    private static function unescapeText(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/', static fn(array $m) => match ($m[1]) {
            'n', 'N' => "\n",
            default => $m[1],
        }, $value);
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
     * The iCalendar to store for an edit: the stored object with this event's
     * fields applied to its master VEVENT, so the UID, VALARMs, attendees and
     * other client data survive. EXDATE, RDATE and RECURRENCE-ID exceptions
     * survive too, unless the series stops recurring or its start moves (they
     * would no longer match). Without usable stored data this is toVCal().
     */
    public function toVCalForUpdate(): string
    {
        if (!$this->storedData || !$this->startDate) {
            return $this->toVCal();
        }

        try {
            $vcalendar = Reader::read($this->storedData);
        } catch (\Throwable) {
            return $this->toVCal();
        }

        $master = null;
        foreach ($vcalendar->VEVENT ?? [] as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                $master = $vevent;
                break;
            }
        }

        if (!$vcalendar instanceof VCalendar || !$master instanceof VEvent) {
            return $this->toVCal();
        }

        $dropExceptions = !$this->recurrenceRule || $this->startMovedFrom($master);

        $master->SUMMARY = $this->title;
        foreach (['DESCRIPTION' => $this->description, 'LOCATION' => $this->location] as $name => $value) {
            if ($value) {
                $master->{$name} = $value;
            } else {
                $master->remove($name);
            }
        }

        $master->remove('DTSTART');
        $master->remove('DTEND');
        $master->remove('DURATION');
        if ($this->isAllDay) {
            $master->add('DTSTART', $this->startDate->format('Ymd'), ['VALUE' => 'DATE']);
            if ($this->endDate) {
                $end = \DateTime::createFromInterface($this->endDate)->modify('+1 day'); // exclusive
                $master->add('DTEND', $end->format('Ymd'), ['VALUE' => 'DATE']);
            }
        } else {
            $master->add('DTSTART', $this->startDate->format('Ymd\THis'), ['TZID' => $this->startDate->getTimezone()->getName()]);
            $this->addMissingTimeZone($vcalendar, $this->startDate);
            if ($this->endDate) {
                $master->add('DTEND', $this->endDate->format('Ymd\THis'), ['TZID' => $this->endDate->getTimezone()->getName()]);
                $this->addMissingTimeZone($vcalendar, $this->endDate);
            }
        }

        if ($this->recurrenceRule) {
            $master->RRULE = $this->recurrenceRule;
        } else {
            $master->remove('RRULE');
        }

        if ($dropExceptions) {
            $master->remove('EXDATE');
            $master->remove('RDATE');
            foreach ($vcalendar->select('VEVENT') as $vevent) {
                if ($vevent !== $master) {
                    $vcalendar->remove($vevent);
                }
            }
        }

        $now = gmdate('Ymd\THis\Z');
        $master->DTSTAMP = $now;
        $master->{'LAST-MODIFIED'} = $now;
        $master->SEQUENCE = isset($master->SEQUENCE) ? (int) (string) $master->SEQUENCE + 1 : 1;

        return $vcalendar->serialize();
    }

    private function startMovedFrom(VEvent $master): bool
    {
        if (!isset($master->DTSTART)) {
            return true;
        }

        $wasAllDay = $master->DTSTART->getValueType() === 'DATE';
        if ($wasAllDay !== $this->isAllDay) {
            return true;
        }

        $storedStart = $master->DTSTART->getDateTime();

        return $this->isAllDay
            ? $storedStart->format('Ymd') !== $this->startDate->format('Ymd')
            : $storedStart->getTimestamp() !== $this->startDate->getTimestamp();
    }

    private function addMissingTimeZone(VCalendar $vcalendar, \DateTimeInterface $date): void
    {
        $tzName = $date->getTimezone()->getName();

        foreach ($vcalendar->select('VTIMEZONE') as $vtimezone) {
            if ((string) $vtimezone->TZID === $tzName) {
                return;
            }
        }

        $generated = Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n".$this->generateVTimeZone($date->getTimezone(), $date)."\r\nEND:VCALENDAR\r\n");
        $vcalendar->add(clone $generated->VTIMEZONE);
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

        // Some timezones (e.g. UTC) have no transitions at all.
        if ($transitions === false) {
            return "BEGIN:VTIMEZONE\r\nTZID:{$tzName}\r\nEND:VTIMEZONE";
        }

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
