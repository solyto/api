<?php

use App\Dav\DTOs\EventDTO;
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
    it('builds a fresh object when there is no stored data, is no iCalendar, or has no VEVENT', function (?string $stored) {
        $vcalendar = Reader::read(editedEvent($stored)->toVCalForUpdate());

        expect((string) $vcalendar->VEVENT->SUMMARY)->toBe('Edited')
            ->and((string) $vcalendar->PRODID)->toBe('-//Solyto//Solyto//EN');
    })->with([
        'no stored data' => null,
        'not iCalendar' => 'garbage',
        'a VTODO' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20300101T000000Z\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
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
