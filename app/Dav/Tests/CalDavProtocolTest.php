<?php

use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function calendarPath(\App\Api\Users\Models\User $user, string $calendar = 'my-calendar', string $object = ''): string
{
    return 'calendars/'.$user->email.'/'.$calendar.'/'.$object;
}

function putEvent(\App\Api\Users\Models\User $user, string $object, string $ics, array $headers = [], string $calendar = 'my-calendar'): \Sabre\HTTP\Response
{
    return davRequest($user, 'PUT', calendarPath($user, $calendar, $object), $ics, ['Content-Type' => 'text/calendar; charset=utf-8'] + $headers);
}

function calendarQueryBody(string $filter, string $props = '<d:getetag/>'): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
        ."<d:prop>{$props}</d:prop><cal:filter><cal:comp-filter name=\"VCALENDAR\">{$filter}</cal:comp-filter></cal:filter>"
        .'</cal:calendar-query>';
}

function syncCollectionBody(string $token = ''): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:sync-collection xmlns:d="DAV:">'
        ."<d:sync-token>{$token}</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop>"
        .'</d:sync-collection>';
}

function propPatchBody(string $props): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:propertyupdate xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:apple="http://apple.com/ns/ical/">'
        ."<d:set><d:prop>{$props}</d:prop></d:set></d:propertyupdate>";
}

describe('CalDAV calendar collections', function () {
    it('creates a calendar with MKCALENDAR that the app then lists', function () {
        $user = davUser();

        $response = davRequest($user, 'MKCALENDAR', calendarPath($user, 'work'), '<?xml version="1.0" encoding="utf-8"?>'
            .'<cal:mkcalendar xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:apple="http://apple.com/ns/ical/">'
            .'<d:set><d:prop><d:displayname>Work</d:displayname><apple:calendar-color>#FF0000FF</apple:calendar-color></d:prop></d:set>'
            .'</cal:mkcalendar>');

        expect($response->getStatus())->toBe(201);

        $calendar = app(DavService::class)->calendars()->getByName($user, 'Work');
        expect($calendar)->not->toBeNull()
            ->and($calendar->color)->toBe('#FF0000FF');
    });

    it('refuses MKCALENDAR on an existing calendar', function () {
        $user = davUser();

        expect(davRequest($user, 'MKCALENDAR', calendarPath($user))->getStatus())->toBe(405);
    });

    it('updates the display name and color with PROPPATCH', function () {
        $user = davUser();

        $patch = davRequest($user, 'PROPPATCH', calendarPath($user), propPatchBody(
            '<d:displayname>Private</d:displayname><apple:calendar-color>#00FF00FF</apple:calendar-color>'
        ));
        $xpath = davXpath(davRequest($user, 'PROPFIND', calendarPath($user), davPropfindBody(['d:displayname', 'apple:calendar-color']), ['Depth' => '0']));

        expect($patch->getStatus())->toBe(207)
            ->and(davXpath($patch)->evaluate('string(//d:status)'))->toContain('200')
            ->and($xpath->evaluate('string(//d:displayname)'))->toBe('Private')
            ->and($xpath->evaluate('string(//apple:calendar-color)'))->toBe('#00FF00FF');
    });

    it('stores both values of schedule-calendar-transp', function (string $value) {
        $user = davUser();

        $patch = davRequest($user, 'PROPPATCH', calendarPath($user), propPatchBody(
            "<cal:schedule-calendar-transp><cal:{$value}/></cal:schedule-calendar-transp>"
        ));
        $xpath = davXpath(davRequest($user, 'PROPFIND', calendarPath($user), davPropfindBody(['cal:schedule-calendar-transp']), ['Depth' => '0']));

        expect(davXpath($patch)->evaluate('string(//d:status)'))->toContain('200')
            ->and($xpath->evaluate("count(//cal:schedule-calendar-transp/cal:{$value})"))->toBe(1.0);
    })->with(['opaque', 'transparent']);

    it('reports the supported components, ctag and sync token', function () {
        $user = davUser();

        $xpath = davXpath(davRequest($user, 'PROPFIND', calendarPath($user), davPropfindBody([
            'cal:supported-calendar-component-set', 'cs:getctag', 'd:sync-token',
        ]), ['Depth' => '0']));

        $components = collect(iterator_to_array($xpath->query('//cal:supported-calendar-component-set/cal:comp/@name')))->map->value;

        // createCalendarCustom() passes 'components' => 'VEVENT', which sabre
        // does not map, so calendars keep its default VEVENT,VTODO.
        expect($components->all())->toBe(['VEVENT', 'VTODO'])
            ->and($xpath->evaluate('string(//cs:getctag)'))->not->toBe('')
            ->and($xpath->evaluate('string(//d:sync-token)'))->not->toBe('');
    });

    it('changes the ctag when an event is written', function () {
        $user = davUser();
        $ctag = fn () => davXpath(davRequest($user, 'PROPFIND', calendarPath($user), davPropfindBody(['cs:getctag']), ['Depth' => '0']))
            ->evaluate('string(//cs:getctag)');

        $before = $ctag();
        putEvent($user, 'a.ics', davIcs('a'));

        expect($ctag())->not->toBe($before);
    });

    it('deletes a calendar with its events', function () {
        $user = davUser();
        davRequest($user, 'MKCALENDAR', calendarPath($user, 'trip'));
        putEvent($user, 'a.ics', davIcs('a'), [], 'trip');

        $response = davRequest($user, 'DELETE', calendarPath($user, 'trip'));

        expect($response->getStatus())->toBe(204)
            ->and(davRequest($user, 'GET', calendarPath($user, 'trip', 'a.ics'))->getStatus())->toBe(404)
            ->and(collect(app(DavService::class)->calendars()->list($user))->pluck('uri'))->not->toContain('trip');
    });
});

describe('CalDAV calendar objects', function () {
    it('stores an event with PUT and returns it unchanged with GET', function () {
        $user = davUser();
        $ics = davIcs('round-trip', 'Dentist');

        $put = putEvent($user, 'dentist.ics', $ics);
        $get = davRequest($user, 'GET', calendarPath($user, 'my-calendar', 'dentist.ics'));

        expect($put->getStatus())->toBe(201)
            ->and($put->getHeader('ETag'))->not->toBeNull()
            ->and($get->getStatus())->toBe(200)
            ->and($get->getBodyAsString())->toBe($ics)
            ->and($get->getHeader('Content-Type'))->toStartWith('text/calendar')
            ->and($get->getHeader('ETag'))->toBe($put->getHeader('ETag'));
    });

    it('refuses to overwrite an existing event when If-None-Match is *', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a'));

        expect(putEvent($user, 'a.ics', davIcs('a', 'Other'), ['If-None-Match' => '*'])->getStatus())->toBe(412);
    });

    it('updates only when If-Match carries the current ETag', function () {
        $user = davUser();
        $etag = putEvent($user, 'a.ics', davIcs('a'))->getHeader('ETag');

        $stale = putEvent($user, 'a.ics', davIcs('a', 'Stale'), ['If-Match' => '"stale"']);
        $current = putEvent($user, 'a.ics', davIcs('a', 'Fresh'), ['If-Match' => $etag]);

        expect($stale->getStatus())->toBe(412)
            ->and($current->getStatus())->toBe(204)
            ->and(davRequest($user, 'GET', calendarPath($user, 'my-calendar', 'a.ics'))->getBodyAsString())->toContain('SUMMARY:Fresh');
    });

    it('deletes an event only when If-Match matches', function () {
        $user = davUser();
        $etag = putEvent($user, 'a.ics', davIcs('a'))->getHeader('ETag');
        $path = calendarPath($user, 'my-calendar', 'a.ics');

        expect(davRequest($user, 'DELETE', $path, null, ['If-Match' => '"stale"'])->getStatus())->toBe(412)
            ->and(davRequest($user, 'DELETE', $path, null, ['If-Match' => $etag])->getStatus())->toBe(204)
            ->and(davRequest($user, 'GET', $path)->getStatus())->toBe(404);
    });

    it('rejects data that is not iCalendar', function (string $body) {
        $user = davUser();

        expect(putEvent($user, 'bad.ics', $body)->getStatus())->toBe(415);
    })->with([
        'garbage' => 'not a calendar',
        'a vCard' => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane\r\nEND:VCARD\r\n",
    ]);

    it('rejects a component the calendar does not support', function () {
        $user = davUser();
        $journal = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Solyto//Tests//EN\r\nBEGIN:VJOURNAL\r\nUID:j1\r\nDTSTAMP:20300101T000000Z\r\nSUMMARY:Entry\r\nEND:VJOURNAL\r\nEND:VCALENDAR\r\n";

        expect(putEvent($user, 'j1.ics', $journal)->getStatus())->toBe(403);
    });

    it('accepts a VTODO, which every calendar supports', function () {
        $user = davUser();
        $todo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Solyto//Tests//EN\r\nBEGIN:VTODO\r\nUID:t1\r\nDTSTAMP:20300101T000000Z\r\nSUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

        expect(putEvent($user, 't1.ics', $todo)->getStatus())->toBe(201);
    });

    // sabre's PDO backend does not enforce the no-uid-conflict precondition.
    it('accepts a second object with the same UID under another name', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('same-uid'));

        expect(putEvent($user, 'b.ics', davIcs('same-uid'))->getStatus())->toBe(201);
    });

    it('answers a missing event with 404', function () {
        $user = davUser();

        expect(davRequest($user, 'GET', calendarPath($user, 'my-calendar', 'missing.ics'))->getStatus())->toBe(404);
    });
});

describe('CalDAV reports', function () {
    it('filters calendar-query by time range', function () {
        $user = davUser();
        putEvent($user, 'october.ics', davIcs('october', 'October', '20301015T100000Z', '20301015T110000Z'));
        putEvent($user, 'december.ics', davIcs('december', 'December', '20301215T100000Z', '20301215T110000Z'));

        $response = davRequest($user, 'REPORT', calendarPath($user), calendarQueryBody(
            '<cal:comp-filter name="VEVENT"><cal:time-range start="20301001T000000Z" end="20301101T000000Z"/></cal:comp-filter>'
        ), ['Depth' => '1']);
        $hrefs = collect(iterator_to_array(davXpath($response)->query('//d:response/d:href')))->map->textContent;

        expect($response->getStatus())->toBe(207)
            ->and($hrefs->all())->toBe(['/'.calendarPath($user, 'my-calendar', 'october.ics')]);
    });

    it('returns calendar data from calendar-query', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a', 'Lunch'));

        $response = davRequest($user, 'REPORT', calendarPath($user), calendarQueryBody(
            '<cal:comp-filter name="VEVENT"/>', '<d:getetag/><cal:calendar-data/>'
        ), ['Depth' => '1']);

        expect(davXpath($response)->evaluate('string(//cal:calendar-data)'))->toContain('SUMMARY:Lunch');
    });

    it('returns the requested events from calendar-multiget and leaves out missing ones', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a', 'First'));
        $present = '/'.calendarPath($user, 'my-calendar', 'a.ics');
        $missing = '/'.calendarPath($user, 'my-calendar', 'missing.ics');

        $response = davRequest($user, 'REPORT', calendarPath($user), '<?xml version="1.0" encoding="utf-8"?>'
            .'<cal:calendar-multiget xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            ."<d:prop><d:getetag/><cal:calendar-data/></d:prop><d:href>{$present}</d:href><d:href>{$missing}</d:href>"
            .'</cal:calendar-multiget>', ['Depth' => '1']);
        $xpath = davXpath($response);

        expect($xpath->evaluate("string(//d:response[d:href='{$present}']//cal:calendar-data)"))->toContain('SUMMARY:First')
            ->and($xpath->evaluate("count(//d:response[d:href='{$missing}'])"))->toBe(0.0);
    });

    it('reports busy time with free-busy-query', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a', 'Busy', '20301015T100000Z', '20301015T110000Z'));

        $response = davRequest($user, 'REPORT', calendarPath($user), '<?xml version="1.0" encoding="utf-8"?>'
            .'<cal:free-busy-query xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            .'<cal:time-range start="20301015T000000Z" end="20301016T000000Z"/></cal:free-busy-query>', ['Depth' => '1']);

        expect($response->getStatus())->toBe(200)
            ->and($response->getBodyAsString())->toContain('FREEBUSY:20301015T100000Z/20301015T110000Z');
    });

    it('syncs changes and deletions with sync-collection', function () {
        $user = davUser();
        putEvent($user, 'kept.ics', davIcs('kept'));
        putEvent($user, 'removed.ics', davIcs('removed'));

        $initial = davRequest($user, 'REPORT', calendarPath($user), syncCollectionBody());
        $initialXpath = davXpath($initial);
        $token = $initialXpath->evaluate('string(/d:multistatus/d:sync-token)');

        putEvent($user, 'added.ics', davIcs('added'));
        putEvent($user, 'kept.ics', davIcs('kept', 'Changed'));
        davRequest($user, 'DELETE', calendarPath($user, 'my-calendar', 'removed.ics'));

        $delta = davXpath(davRequest($user, 'REPORT', calendarPath($user), syncCollectionBody($token)));
        $base = '/'.calendarPath($user);

        expect($initial->getStatus())->toBe(207)
            ->and($initialXpath->evaluate('count(//d:response)'))->toBe(2.0)
            ->and($token)->not->toBe('')
            ->and($delta->evaluate("count(//d:response[d:href='{$base}added.ics']/d:propstat)"))->toBe(1.0)
            ->and($delta->evaluate("count(//d:response[d:href='{$base}kept.ics']/d:propstat)"))->toBe(1.0)
            ->and($delta->evaluate("string(//d:response[d:href='{$base}removed.ics']/d:status)"))->toContain('404')
            ->and($delta->evaluate('string(/d:multistatus/d:sync-token)'))->not->toBe($token);
    });

    it('rejects a malformed sync token', function () {
        $user = davUser();

        $response = davRequest($user, 'REPORT', calendarPath($user), syncCollectionBody('garbage'));

        expect($response->getStatus())->toBe(403)
            ->and($response->getBodyAsString())->toContain('valid-sync-token');
    });

    it('answers a numeric sync token from the future with no changes', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a'));

        $xpath = davXpath(davRequest($user, 'REPORT', calendarPath($user), syncCollectionBody('http://sabre.io/ns/sync/9999')));

        expect($xpath->evaluate('count(//d:response)'))->toBe(0.0)
            ->and($xpath->evaluate('string(/d:multistatus/d:sync-token)'))->toBe('http://sabre.io/ns/sync/9999');
    });
});

describe('CalDAV MOVE, COPY and ranges', function () {
    it('moves an event to another calendar', function () {
        $user = davUser();
        davRequest($user, 'MKCALENDAR', calendarPath($user, 'work'));
        putEvent($user, 'a.ics', davIcs('a'));

        $response = davRequest($user, 'MOVE', calendarPath($user, 'my-calendar', 'a.ics'), null, [
            'Destination' => '/'.calendarPath($user, 'work', 'a.ics'),
        ]);

        expect($response->getStatus())->toBe(201)
            ->and(davRequest($user, 'GET', calendarPath($user, 'my-calendar', 'a.ics'))->getStatus())->toBe(404)
            ->and(davRequest($user, 'GET', calendarPath($user, 'work', 'a.ics'))->getStatus())->toBe(200);
    });

    it('refuses MOVE with a Depth other than infinity', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a'));

        $response = davRequest($user, 'MOVE', calendarPath($user, 'my-calendar', 'a.ics'), null, [
            'Destination' => '/'.calendarPath($user, 'my-calendar', 'b.ics'),
            'Depth' => '0',
        ]);

        expect($response->getStatus())->toBe(400)
            ->and(davRequest($user, 'GET', calendarPath($user, 'my-calendar', 'a.ics'))->getStatus())->toBe(200);
    });

    it('copies an event and keeps the original', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a'));

        $response = davRequest($user, 'COPY', calendarPath($user, 'my-calendar', 'a.ics'), null, [
            'Destination' => '/'.calendarPath($user, 'my-calendar', 'copy.ics'),
        ]);

        expect($response->getStatus())->toBe(201)
            ->and(davRequest($user, 'GET', calendarPath($user, 'my-calendar', 'a.ics'))->getStatus())->toBe(200)
            ->and(davRequest($user, 'GET', calendarPath($user, 'my-calendar', 'copy.ics'))->getStatus())->toBe(200);
    });

    it('serves a byte range on GET and ignores Range on HEAD', function () {
        $user = davUser();
        putEvent($user, 'a.ics', davIcs('a'));
        $path = calendarPath($user, 'my-calendar', 'a.ics');

        $get = davRequest($user, 'GET', $path, null, ['Range' => 'bytes=0-9']);
        $head = davRequest($user, 'HEAD', $path, null, ['Range' => 'bytes=0-9']);

        expect($get->getStatus())->toBe(206)
            ->and($get->getBodyAsString())->toBe('BEGIN:VCAL')
            ->and($head->getStatus())->toBe(200);
    });
});
