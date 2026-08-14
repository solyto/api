<?php

use App\Dav\Helpers\ICalendarHelper;

covers(ICalendarHelper::class);

describe('ICalendarHelper::parseCalendarsFromXml', function () {
    it('parses calendars from a multistatus response', function () {
        $xml = <<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:ical="http://apple.com/ns/ical/">
  <d:response>
    <d:href>/calendars/john/private/</d:href>
    <d:propstat>
      <d:prop>
        <d:displayname>Private</d:displayname>
        <d:resourcetype><c:calendar/></d:resourcetype>
        <ical:calendar-color>#FF0000</ical:calendar-color>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/calendars/john/work/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><c:calendar/></d:resourcetype>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
XML;

        $calendars = (new ICalendarHelper)->parseCalendarsFromXml($xml, 'https://dav.example.com');

        expect($calendars)->toHaveCount(2);
        expect($calendars[0])->toBe([
            'url' => 'https://dav.example.com/calendars/john/private/',
            'name' => 'Private',
            'color' => '#FF0000',
        ]);
        expect($calendars[1]['name'])->toBe('Unnamed Calendar');
        expect($calendars[1]['color'])->toBe('#e5e7eb');
    });

    it('returns an empty array for invalid xml', function () {
        expect((new ICalendarHelper)->parseCalendarsFromXml('not xml', 'https://x.com'))->toBe([]);
    });
});

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

describe('ICalendarHelper::parseCalendarHomeFromXml', function () {
    it('parses the calendar home set', function () {
        $xml = <<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/</d:href>
    <d:propstat>
      <d:prop>
        <cal:calendar-home-set>
          <d:href>/remote.php/dav/calendars/john/</d:href>
        </cal:calendar-home-set>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
XML;

        $homes = (new ICalendarHelper)->parseCalendarHomeFromXml($xml, 'https://nc.example.com');

        expect($homes)->toHaveCount(1);
        expect($homes[0]['url'])->toBe('https://nc.example.com/remote.php/dav/calendars/john/');
    });
});

describe('ICalendarHelper xml builders', function () {
    it('builds the calendars propfind xml', function () {
        $xml = ICalendarHelper::getCalendarsXml();

        expect($xml)->toBeString();
        expect($xml)->toContain('propfind');
        expect($xml)->toContain('calendar-color');
    });

    it('builds the entries query xml with a time range', function () {
        $xml = ICalendarHelper::getEntriesXml();

        expect($xml)->toBeString();
        expect($xml)->toContain('calendar-query');
        expect($xml)->toContain('time-range');
    });

    it('builds the calendar home discovery xml', function () {
        $xml = ICalendarHelper::getCalendarHomeDiscoveryXml();

        expect($xml)->toBeString();
        expect($xml)->toContain('calendar-home-set');
    });
});
