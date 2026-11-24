<?php

use App\Dav\Helpers\ICalendarHelper;

covers(ICalendarHelper::class);

function calendarQueryResponse(array $responses): string
{
    return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        .implode('', array_map(fn (?string $ical) => '<d:response><d:href>/x.ics</d:href><d:propstat><d:prop><d:getetag>"abc"</d:getetag>'
            .($ical === null ? '' : '<c:calendar-data>'.htmlspecialchars($ical).'</c:calendar-data>')
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>', $responses))
        .'</d:multistatus>';
}

describe('ICalendarHelper::calendarDataFromXml', function () {
    it('returns the calendar data of each response unchanged', function () {
        $first = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:a\nDTSTART;TZID=Europe/Berlin:20260115T100000\nSUMMARY:A & B\nEND:VEVENT\nEND:VCALENDAR";
        $second = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:b\nDTSTART:20260116T100000Z\nEND:VEVENT\nEND:VCALENDAR";

        $data = (new ICalendarHelper)->calendarDataFromXml(calendarQueryResponse([$first, null, $second]));

        expect($data)->toBe([$first, $second]);
    });

    it('returns nothing for a body that is not XML', function () {
        expect((new ICalendarHelper)->calendarDataFromXml('nope'))->toBe([]);
    });
});

describe('ICalendarHelper xml builders', function () {
    it('builds the entries query xml with a time range', function () {
        $xml = ICalendarHelper::getEntriesXml();

        expect($xml)->toBeString();
        expect($xml)->toContain('calendar-query');
        expect($xml)->toContain('time-range');
    });
});
