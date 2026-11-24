<?php

use App\Dav\DTOs\EventDTO;
use App\Dav\Exceptions\UnreadableCalendarObjectException;
use Sabre\VObject\Reader;

covers(EventDTO::class);

function editedEvent(?string $storedData, ?string $recurrenceRule = null): EventDTO
{
    $utc = new \DateTimeZone('UTC');
    $event = new EventDTO(
        id: 1, title: 'Edited', description: null, startDate: new \DateTime('2030-10-14 09:00:00', $utc),
        endDate: new \DateTime('2030-10-14 09:15:00', $utc), isAllDay: false, location: null, recurrenceRule: $recurrenceRule,
        recurrenceEnd: null, timezone: 'UTC', uri: 'a.ics', etag: null, calendarId: null, calendarName: null,
    );
    $event->storedData = $storedData;

    return $event;
}

describe('EventDTO::toVCalForUpdate', function () {
    it('builds a fresh object when there is no stored data', function () {
        $vcalendar = Reader::read(editedEvent(null)->toVCalForUpdate());

        expect((string) $vcalendar->VEVENT->SUMMARY)->toBe('Edited')
            ->and((string) $vcalendar->PRODID)->toBe('-//Solyto//Solyto//EN');
    });

    // Rebuilding from scratch would drop what other clients stored (UID, reminders, attendees).
    it('refuses to edit stored data it cannot read, instead of overwriting it', function (string $stored) {
        expect(fn () => editedEvent($stored)->toVCalForUpdate())->toThrow(UnreadableCalendarObjectException::class);
    })->with([
        'not iCalendar' => 'garbage',
        'a vCard' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c\r\nFN:Jane Doe\r\nN:Doe;Jane;;;\r\nEND:VCARD\r\n",
        'a VTODO' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20300101T000000Z\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
        'only a changed occurrence' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:s\r\nDTSTAMP:20300101T000000Z\r\nRECURRENCE-ID:20301016T090000Z\r\nDTSTART:20301016T110000Z\r\nSUMMARY:Moved\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
    ]);

    it('removes the repeat rule with its deleted and moved dates when the event stops recurring', function () {
        $stored = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//EN',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'DTSTART:20301014T090000Z', 'DTEND:20301014T091500Z',
            'RRULE:FREQ=DAILY;COUNT=4', 'EXDATE:20301015T090000Z', 'RDATE:20301020T090000Z', 'SUMMARY:Standup', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID:20301016T090000Z',
            'DTSTART:20301016T110000Z', 'DTEND:20301016T111500Z', 'SUMMARY:Moved', 'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";

        $vcalendar = Reader::read(editedEvent($stored)->toVCalForUpdate());

        expect(count($vcalendar->VEVENT))->toBe(1)
            ->and(isset($vcalendar->VEVENT->RRULE))->toBeFalse()
            ->and(isset($vcalendar->VEVENT->EXDATE))->toBeFalse()
            ->and(isset($vcalendar->VEVENT->RDATE))->toBeFalse()
            ->and((string) $vcalendar->VEVENT->UID)->toBe('series');
    });

    it('edits the master event even when an exception comes first', function () {
        $stored = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//EN',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID:20301016T090000Z',
            'DTSTART:20301016T110000Z', 'DTEND:20301016T111500Z', 'SUMMARY:Moved', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'DTSTART:20301014T090000Z', 'DTEND:20301014T091500Z',
            'RRULE:FREQ=DAILY;COUNT=4', 'SUMMARY:Standup', 'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";

        $vcalendar = Reader::read(editedEvent($stored, 'FREQ=DAILY;COUNT=4')->toVCalForUpdate());
        $summaries = collect($vcalendar->VEVENT)->map(fn ($vevent) => (string) $vevent->SUMMARY)->all();

        expect($summaries)->toBe(['Moved', 'Edited']);
    });
});

// Byte for byte apart from the write time: what DAV clients get back after an edit in the app.
describe('EventDTO::toVCalForUpdate output', function () {
    it('writes the edited master exactly as before', function (string $storedKind, string $start, string $end, string $timezone, ?string $description, ?string $location, string $expected) {
        $stored = [
            'timed' => implode("\r\n", [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//EN',
                'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'DTSTART:20301014T090000Z', 'DURATION:PT15M',
                'RRULE:FREQ=DAILY;COUNT=4', 'EXDATE:20301015T090000Z', 'SUMMARY:Standup', 'DESCRIPTION:Old', 'LOCATION:Room 1', 'SEQUENCE:3',
                'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT5M', 'DESCRIPTION:Soon', 'END:VALARM', 'END:VEVENT',
                'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID:20301016T090000Z',
                'DTSTART:20301016T110000Z', 'DTEND:20301016T111500Z', 'SUMMARY:Moved', 'END:VEVENT',
                'END:VCALENDAR',
            ])."\r\n",
            'all day' => implode("\r\n", [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//EN',
                'BEGIN:VEVENT', 'UID:bins', 'DTSTAMP:20300101T000000Z', 'DTSTART;VALUE=DATE:20301007', 'DTEND;VALUE=DATE:20301008',
                'RRULE:FREQ=WEEKLY;COUNT=3', 'EXDATE;VALUE=DATE:20301014', 'SUMMARY:Bin day', 'DESCRIPTION:Old', 'END:VEVENT',
                'BEGIN:VEVENT', 'UID:bins', 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID;VALUE=DATE:20301021',
                'DTSTART;VALUE=DATE:20301022', 'DTEND;VALUE=DATE:20301023', 'SUMMARY:Late bins', 'END:VEVENT',
                'END:VCALENDAR',
            ])."\r\n",
        ][$storedKind];
        $isAllDay = $storedKind === 'all day';
        $zone = new \DateTimeZone($timezone);
        $event = new EventDTO(
            id: 1, title: 'Edited', description: $description, startDate: new \DateTime($start, $zone),
            endDate: new \DateTime($end, $zone), isAllDay: $isAllDay, location: $location,
            recurrenceRule: $isAllDay ? 'FREQ=WEEKLY;COUNT=3' : 'FREQ=DAILY;COUNT=4',
            recurrenceEnd: null, timezone: $timezone, uri: 'a.ics', etag: null, calendarId: null, calendarName: null,
        );
        $event->storedData = $stored;

        $written = preg_replace('/(DTSTAMP|LAST-MODIFIED):(?!20300101T000000Z)\d{8}T\d{6}Z/', '$1:written', $event->toVCalForUpdate());

        expect($written)->toBe($expected);
    })->with([
        'a timed series moved, in another zone' => ['timed', '2030-10-14 12:00:00', '2030-10-14 12:30:00', 'Europe/Berlin', 'New', null,
            implode("\r\n", [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//EN', 'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin',
                'BEGIN:STANDARD', 'DTSTART:20300101T000000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0100', 'TZNAME:STANDARD',
                'END:STANDARD', 'BEGIN:DAYLIGHT', 'DTSTART:20300331T010000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200',
                'TZNAME:DAYLIGHT', 'END:DAYLIGHT', 'END:VTIMEZONE', 'BEGIN:VEVENT', 'UID:series', 'SUMMARY:Edited',
                'DESCRIPTION:New', 'DTSTART;TZID=Europe/Berlin:20301014T120000', 'DTEND;TZID=Europe/Berlin:20301014T123000',
                'RRULE:FREQ=DAILY;COUNT=4', 'DTSTAMP:written', 'LAST-MODIFIED:written', 'SEQUENCE:4', 'BEGIN:VALARM',
                'ACTION:DISPLAY', 'TRIGGER:-PT5M', 'DESCRIPTION:Soon', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR',
            ])."\r\n",
        ],
        'a timed series at the same instant, in another zone' => ['timed', '2030-10-14 11:00:00', '2030-10-14 11:15:00', 'Europe/Berlin', null, 'Room 2',
            implode("\r\n", [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//EN', 'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin',
                'BEGIN:STANDARD', 'DTSTART:20300101T000000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0100', 'TZNAME:STANDARD',
                'END:STANDARD', 'BEGIN:DAYLIGHT', 'DTSTART:20300331T010000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200',
                'TZNAME:DAYLIGHT', 'END:DAYLIGHT', 'END:VTIMEZONE', 'BEGIN:VEVENT', 'UID:series', 'EXDATE:20301015T090000Z',
                'SUMMARY:Edited', 'LOCATION:Room 2', 'DTSTART;TZID=Europe/Berlin:20301014T110000',
                'DTEND;TZID=Europe/Berlin:20301014T111500', 'RRULE:FREQ=DAILY;COUNT=4', 'DTSTAMP:written',
                'LAST-MODIFIED:written', 'SEQUENCE:4', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT5M', 'DESCRIPTION:Soon',
                'END:VALARM', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z',
                'RECURRENCE-ID:20301016T090000Z', 'DTSTART:20301016T110000Z', 'DTEND:20301016T111500Z', 'SUMMARY:Moved',
                'END:VEVENT', 'END:VCALENDAR',
            ])."\r\n",
        ],
        'an all-day series on the same day' => ['all day', '2030-10-07 00:00:00', '2030-10-08 00:00:00', 'UTC', '', 'Yard',
            implode("\r\n", [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//t//EN', 'BEGIN:VEVENT', 'UID:bins',
                'EXDATE;VALUE=DATE:20301014', 'SUMMARY:Edited', 'LOCATION:Yard', 'DTSTART;VALUE=DATE:20301007',
                'DTEND;VALUE=DATE:20301009', 'RRULE:FREQ=WEEKLY;COUNT=3', 'DTSTAMP:written', 'LAST-MODIFIED:written',
                'SEQUENCE:1', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:bins', 'DTSTAMP:20300101T000000Z',
                'RECURRENCE-ID;VALUE=DATE:20301021', 'DTSTART;VALUE=DATE:20301022', 'DTEND;VALUE=DATE:20301023',
                'SUMMARY:Late bins', 'END:VEVENT', 'END:VCALENDAR',
            ])."\r\n",
        ],
    ]);
});
