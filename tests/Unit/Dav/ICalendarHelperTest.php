<?php

use App\Dav\Helpers\ICalendarHelper;

covers(ICalendarHelper::class);

describe('ICalendarHelper::parseEntriesFromXml', function () {
    it('parses calendar events from a query response', function () {
        $ical = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-1
SUMMARY:Team Meeting
DESCRIPTION:Weekly sync
LOCATION:Room 1
DTSTART:20260115T100000Z
DTEND:20260115T110000Z
END:VEVENT
END:VCALENDAR
ICS;

        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/calendars/john/private/event-1.ics</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"abc123"</d:getetag>
        <c:calendar-data>'.htmlspecialchars($ical).'</c:calendar-data>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>';

        $events = (new ICalendarHelper)->parseEntriesFromXml($xml, 'private');

        expect($events)->toHaveCount(1);
        $event = $events[0];
        expect($event['uid'])->toBe('event-1');
        expect($event['title'])->toBe('Team Meeting');
        expect($event['description'])->toBe('Weekly sync');
        expect($event['location'])->toBe('Room 1');
        expect($event['all_day'])->toBeFalse();
        expect($event['is_recurring'])->toBeFalse();
        expect($event['etag'])->toBe('abc123');
        expect($event['url'])->toBe('/calendars/john/private/event-1.ics');
        expect($event['calendar_name'])->toBe('private');
    });

    it('parses all-day events', function () {
        $ical = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:allday-1
SUMMARY:Holiday
DTSTART;VALUE=DATE:20260214
DTEND;VALUE=DATE:20260215
END:VEVENT
END:VCALENDAR
ICS;

        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/x.ics</d:href>
    <d:propstat>
      <d:prop>
        <c:calendar-data>'.htmlspecialchars($ical).'</c:calendar-data>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>';

        $events = (new ICalendarHelper)->parseEntriesFromXml($xml, 'cal');

        expect($events)->toHaveCount(1);
        expect($events[0]['all_day'])->toBeTrue();
    });

    it('parses recurring events with an rrule', function () {
        $ical = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:rec-1
SUMMARY:Daily Standup
DTSTART:20260116T090000Z
DTEND:20260116T093000Z
RRULE:FREQ=DAILY
END:VEVENT
END:VCALENDAR
ICS;

        $xml = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/r.ics</d:href>
    <d:propstat>
      <d:prop>
        <c:calendar-data>'.htmlspecialchars($ical).'</c:calendar-data>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>';

        $events = (new ICalendarHelper)->parseEntriesFromXml($xml, 'cal');

        expect($events)->toHaveCount(1);
        expect($events[0]['is_recurring'])->toBeTrue();
        expect($events[0]['recurrence_rule'])->toBe('FREQ=DAILY');
    });

    it('returns an empty array for invalid xml', function () {
        expect((new ICalendarHelper)->parseEntriesFromXml('nope', 'cal'))->toBe([]);
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
