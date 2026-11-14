<?php

use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeCalDavServer;

uses(RefreshDatabase::class);

function remotePath(\App\Api\Users\Models\User $user, string $object = ''): string
{
    return 'calendars/'.$user->email.'/remote/'.$object;
}

function remoteObjectCount(\App\Dav\DTOs\CalendarDTO $calendar): int
{
    return DB::connection('pgsql')->table('calendarobjects')->where('calendarid', $calendar->calendarId)->count();
}

function remotePut(\App\Api\Users\Models\User $user, string $object, string $ics, array $headers = []): \Sabre\HTTP\Response
{
    return davRequest($user, 'PUT', remotePath($user, $object), $ics, ['Content-Type' => 'text/calendar; charset=utf-8'] + $headers);
}

function remoteSyncBody(string $token = ''): string
{
    return '<?xml version="1.0" encoding="utf-8"?><d:sync-collection xmlns:d="DAV:">'
        ."<d:sync-token>{$token}</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>";
}

beforeEach(function () {
    $this->remote = (new FakeCalDavServer())->fake();
    $this->user = davUser();
    $this->calendar = externalCalendar($this->user, $this->remote);
});

describe('External calendars over DAV', function () {
    it('serves a remote object by GET with the remote ETag', function () {
        $etag = $this->remote->put('a.ics', davIcs('a', 'Remote event'));

        $response = davRequest($this->user, 'GET', remotePath($this->user, 'a.ics'));

        expect($response->getStatus())->toBe(200)
            ->and($response->getBodyAsString())->toContain('SUMMARY:Remote event')
            ->and($response->getHeader('ETag'))->toBe($etag);
    });

    it('lists remote objects with PROPFIND Depth 1', function () {
        $this->remote->put('a.ics', davIcs('a'));
        $this->remote->put('b.ics', davIcs('b'));

        $xpath = davXpath(davRequest($this->user, 'PROPFIND', remotePath($this->user), davPropfindBody(['d:getetag']), ['Depth' => '1']));

        expect($xpath->evaluate('count(//d:response)'))->toBe(3.0)
            ->and($xpath->evaluate('string(//d:response[contains(d:href, "a.ics")]//d:getetag)'))->toBe($this->remote->objects['a.ics']['etag']);
    });

    it('creates, updates and deletes on the remote and stores nothing locally', function () {
        $create = remotePut($this->user, 'new.ics', davIcs('new', 'Created'));

        expect($create->getStatus())->toBe(201)
            ->and($this->remote->objects['new.ics']['data'])->toContain('SUMMARY:Created')
            ->and(collect($this->remote->requests)->firstWhere(fn ($r) => $r->method() === 'PUT')->header('If-None-Match'))->toBe(['*']);

        $etag = $this->remote->objects['new.ics']['etag'];
        $update = remotePut($this->user, 'new.ics', davIcs('new', 'Updated'), ['If-Match' => $etag]);

        expect($update->getStatus())->toBe(204)
            ->and($this->remote->objects['new.ics']['data'])->toContain('SUMMARY:Updated');

        $delete = davRequest($this->user, 'DELETE', remotePath($this->user, 'new.ics'));

        expect($delete->getStatus())->toBe(204)
            ->and($this->remote->objects)->not->toHaveKey('new.ics')
            ->and(remoteObjectCount($this->calendar))->toBe(0);
    });

    it('returns a 412 when the client ETag is stale', function () {
        $this->remote->put('a.ics', davIcs('a', 'Old'));
        $stale = $this->remote->objects['a.ics']['etag'];
        $this->remote->put('a.ics', davIcs('a', 'Changed elsewhere'));

        $response = remotePut($this->user, 'a.ics', davIcs('a', 'Mine'), ['If-Match' => $stale]);

        expect($response->getStatus())->toBe(412)
            ->and($this->remote->objects['a.ics']['data'])->toContain('Changed elsewhere');
    });

    it('updates and deletes on a remote that sends weak ETags', function () {
        $this->remote->weakEtags = true;
        $this->remote->put('a.ics', davIcs('a', 'Old'));
        $weak = davRequest($this->user, 'GET', remotePath($this->user, 'a.ics'))->getHeader('ETag');

        $update = remotePut($this->user, 'a.ics', davIcs('a', 'Updated'), ['If-Match' => $weak]);

        expect($weak)->toStartWith('W/"')
            ->and($update->getStatus())->toBe(204)
            ->and($this->remote->objects['a.ics']['data'])->toContain('SUMMARY:Updated');

        $weak = davRequest($this->user, 'GET', remotePath($this->user, 'a.ics'))->getHeader('ETag');
        $delete = davRequest($this->user, 'DELETE', remotePath($this->user, 'a.ics'), null, ['If-Match' => $weak]);

        expect($delete->getStatus())->toBe(204)
            ->and($this->remote->objects)->not->toHaveKey('a.ics');
    });

    it('passes a remote 412 through when the object changes after the precondition check', function () {
        $etag = $this->remote->put('a.ics', davIcs('a', 'Old'));
        $remote = $this->remote;
        $gets = 0;
        Http::fake(function ($request) use ($remote, &$gets) {
            $response = $remote->handle($request);
            if ($request->method() === 'GET' && ++$gets === 1) {
                $remote->put('a.ics', davIcs('a', 'Raced'));
            }

            return $response;
        });

        $response = remotePut($this->user, 'a.ics', davIcs('a', 'Mine'), ['If-Match' => $etag]);

        expect($response->getStatus())->toBe(412)
            ->and($remote->objects['a.ics']['data'])->toContain('Raced');
    });

    it('answers calendar-query with the remote objects in range', function () {
        $this->remote->put('in.ics', davIcs('in', 'In', '20301015T100000Z', '20301015T110000Z'));
        $this->remote->put('out.ics', davIcs('out', 'Out', '20301120T100000Z', '20301120T110000Z'));

        $body = '<?xml version="1.0" encoding="utf-8"?><cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            .'<d:prop><d:getetag/><cal:calendar-data/></d:prop><cal:filter><cal:comp-filter name="VCALENDAR">'
            .'<cal:comp-filter name="VEVENT"><cal:time-range start="20301001T000000Z" end="20301031T000000Z"/></cal:comp-filter>'
            .'</cal:comp-filter></cal:filter></cal:calendar-query>';
        $response = davRequest($this->user, 'REPORT', remotePath($this->user), $body, ['Depth' => '1']);
        $xpath = davXpath($response);

        expect($response->getStatus())->toBe(207)
            ->and($xpath->evaluate('count(//d:response)'))->toBe(1.0)
            ->and($xpath->evaluate('string(//cal:calendar-data)'))->toContain('SUMMARY:In');
    });

    it('answers calendar-multiget from the remote', function () {
        $this->remote->put('a.ics', davIcs('a', 'First'));
        $this->remote->put('b.ics', davIcs('b', 'Second'));

        $body = '<?xml version="1.0"?><cal:calendar-multiget xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            .'<d:prop><d:getetag/><cal:calendar-data/></d:prop><d:href>/'.remotePath($this->user, 'b.ics').'</d:href></cal:calendar-multiget>';
        $xpath = davXpath(davRequest($this->user, 'REPORT', remotePath($this->user), $body, ['Depth' => '1']));

        expect($xpath->evaluate('count(//d:response)'))->toBe(1.0)
            ->and($xpath->evaluate('string(//cal:calendar-data)'))->toContain('SUMMARY:Second');
    });

    it('round-trips URL-encoded object names', function () {
        $this->remote->put('event one+ü.ics', davIcs('enc', 'Encoded'));

        $xpath = davXpath(davRequest($this->user, 'PROPFIND', remotePath($this->user), davPropfindBody(['d:getetag']), ['Depth' => '1']));
        $href = collect(iterator_to_array($xpath->query('//d:href')))->map(fn ($node) => $node->textContent)->first(fn ($href) => str_contains($href, '.ics'));

        $get = davRequest($this->user, 'GET', ltrim(rawurldecode($href), '/'));
        $put = remotePut($this->user, 'second name.ics', davIcs('enc2'));

        expect($get->getStatus())->toBe(200)
            ->and($get->getBodyAsString())->toContain('SUMMARY:Encoded')
            ->and($put->getStatus())->toBe(201)
            ->and($this->remote->objects)->toHaveKey('second name.ics')
            ->and(collect($this->remote->requests)->last(fn ($r) => $r->method() === 'PUT')->url())->toEndWith('/second%20name.ics');
    });

    it('skips and logs remote hrefs that are not direct children', function () {
        $this->remote->put('a.ics', davIcs('a'));
        $this->remote->extraHrefs = [
            $this->remote->path.'sub/b.ics',
            'https://other.example.com'.$this->remote->path.'c.ics',
        ];
        Log::shouldReceive('channel')->with('dav')->andReturnSelf();
        Log::shouldReceive('warning')->twice()->with('External calendar href skipped: not a direct child of the collection', Mockery::any());

        $xpath = davXpath(davRequest($this->user, 'PROPFIND', remotePath($this->user), davPropfindBody(['d:getetag']), ['Depth' => '1']));

        expect($xpath->evaluate('count(//d:response)'))->toBe(2.0);
    });

    it('passes the remote sync token through and reports changes', function () {
        $this->remote->put('a.ics', davIcs('a'));

        $propfind = davXpath(davRequest($this->user, 'PROPFIND', remotePath($this->user), davPropfindBody(['d:sync-token', 'cs:getctag']), ['Depth' => '0']));
        $token = $propfind->evaluate('string(//d:sync-token)');

        expect($token)->toStartWith('http://sabre.io/ns/sync/ext-')
            ->and($propfind->evaluate('string(//cs:getctag)'))->toBe('ctag-2');

        $initial = davXpath(davRequest($this->user, 'REPORT', remotePath($this->user), remoteSyncBody(), ['Depth' => '1']));
        $first = $initial->evaluate('string(/d:multistatus/d:sync-token)');

        expect($initial->evaluate('count(//d:response)'))->toBe(1.0);

        $this->remote->put('b.ics', davIcs('b'));
        $this->remote->remove('a.ics');

        $delta = davXpath(davRequest($this->user, 'REPORT', remotePath($this->user), remoteSyncBody($first), ['Depth' => '1']));

        expect($delta->evaluate('count(//d:response[contains(d:href, "b.ics")]//d:getetag)'))->toBe(1.0)
            ->and($delta->evaluate('string(//d:response[contains(d:href, "a.ics")]/d:status)'))->toContain('404')
            ->and($delta->evaluate('string(/d:multistatus/d:sync-token)'))->toBe('http://sabre.io/ns/sync/ext-'.rtrim(strtr(base64_encode($this->remote->syncToken()), '+/', '-_'), '='));
    });

    it('rejects a sync token the remote does not know', function () {
        $response = davRequest($this->user, 'REPORT', remotePath($this->user), remoteSyncBody('http://sabre.io/ns/sync/12'), ['Depth' => '1']);

        expect($response->getStatus())->toBe(403)
            ->and($response->getBodyAsString())->toContain('valid-sync-token');
    });

    it('offers no sync token when the remote has no sync-collection', function () {
        $this->remote->supportsSync = false;

        $xpath = davXpath(davRequest($this->user, 'PROPFIND', remotePath($this->user), davPropfindBody(['d:sync-token', 'cs:getctag']), ['Depth' => '0']));

        expect($xpath->evaluate('string(//d:propstat[contains(d:status, "200")]//d:sync-token)'))->toBe('')
            ->and($xpath->evaluate('string(//cs:getctag)'))->toBe('ctag-1');
    });

    it('answers 503 for the unreachable collection only and keeps it in the home listing', function () {
        $this->remote->down = true;

        $home = davXpath(davRequest($this->user, 'PROPFIND', 'calendars/'.$this->user->email.'/', davPropfindBody(['d:displayname']), ['Depth' => '1']));
        $collection = davRequest($this->user, 'PROPFIND', remotePath($this->user), davPropfindBody(['d:getetag']), ['Depth' => '1']);
        $object = davRequest($this->user, 'GET', remotePath($this->user, 'a.ics'));
        $native = davRequest($this->user, 'PROPFIND', 'calendars/'.$this->user->email.'/my-calendar/', davPropfindBody(['d:getetag']), ['Depth' => '1']);

        expect($home->evaluate('count(//d:displayname[text()="Remote"])'))->toBe(1.0)
            ->and($collection->getStatus())->toBe(503)
            ->and($object->getStatus())->toBe(503)
            ->and($native->getStatus())->toBe(207);
    });

    it('refuses to share an external calendar over DAV', function () {
        $friend = davUser();
        $body = '<?xml version="1.0" encoding="utf-8"?><d:share-resource xmlns:d="DAV:"><d:sharee><d:href>mailto:'.$friend->email.'</d:href><d:share-access><d:read/></d:share-access></d:sharee></d:share-resource>';

        $response = davRequest($this->user, 'POST', remotePath($this->user), $body, ['Content-Type' => 'application/davsharing+xml; charset=utf-8']);

        expect($response->getStatus())->toBe(403)
            ->and(DB::connection('pgsql')->table('calendarinstances')->where('calendarid', $this->calendar->calendarId)->count())->toBe(1);
    });

    it('removes only the link and the local row when the collection is deleted', function () {
        $this->remote->put('a.ics', davIcs('a'));

        $response = davRequest($this->user, 'DELETE', remotePath($this->user));

        expect($response->getStatus())->toBe(204)
            ->and(\App\Api\Calendars\Models\ExternalCalendar::count())->toBe(0)
            ->and(app(DavService::class)->calendars()->get($this->user, $this->calendar->instanceId))->toBeNull()
            ->and($this->remote->objects)->toHaveKey('a.ics')
            ->and(collect($this->remote->requests)->pluck('method')->filter(fn ($m) => $m === 'DELETE'))->toBeEmpty();
    });

    it('keeps native calendars on pgsql', function () {
        davRequest($this->user, 'PUT', 'calendars/'.$this->user->email.'/my-calendar/native.ics', davIcs('native'), ['Content-Type' => 'text/calendar; charset=utf-8']);

        $native = app(DavService::class)->calendars()->getByName($this->user, 'My Calendar');

        expect(remoteObjectCount($native))->toBe(1)
            ->and($native->isExternal)->toBeFalse()
            ->and($this->calendar->isExternal)->toBeTrue()
            ->and(collect($this->remote->requests)->filter(fn ($r) => in_array($r->method(), ['PUT', 'DELETE'], true)))->toBeEmpty();
    });
});
