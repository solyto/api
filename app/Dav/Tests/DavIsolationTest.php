<?php

use App\Api\Users\Models\User;
use App\Dav\Backend\AppCalendarsPDO;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function sharedInstanceUri(User $sharee): string
{
    return DB::connection('pgsql')->table('calendarinstances')
        ->where('principaluri', 'principals/'.$sharee->email)
        ->where('access', '>', 1)
        ->value('uri');
}

function shareCalendar(User $owner, User $sharee, bool $writeAccess, bool $accept = true): string
{
    $dav = app(DavService::class);
    $calendar = $dav->calendars()->list($owner)[0];

    (new AppCalendarsPDO(DB::connection('pgsql')->getPdo()))->shareCalendarWithUserCustom($owner, $sharee, $calendar, $writeAccess);

    if ($accept) {
        $dav->calendars()->sharing()->acceptInvite('mailto:'.$sharee->email);
    }

    return 'calendars/'.$sharee->email.'/'.sharedInstanceUri($sharee).'/';
}

beforeEach(function () {
    $this->owner = davUser();
    $this->other = davUser();
    $this->calendar = 'calendars/'.$this->owner->email.'/my-calendar/';
    $this->addressBook = 'addressbooks/'.$this->owner->email.'/contacts/';

    davRequest($this->owner, 'PUT', $this->calendar.'secret.ics', davIcs('secret', 'Secret meeting'), ['Content-Type' => 'text/calendar']);
    davRequest($this->owner, 'PUT', $this->addressBook.'secret.vcf', davVcard('secret', 'Secret Person'), ['Content-Type' => 'text/vcard']);
});

describe('DAV isolation: reading another user\'s data', function () {
    it('denies GET on another user\'s event and card', function () {
        expect(davRequest($this->other, 'GET', $this->calendar.'secret.ics')->getStatus())->toBe(403)
            ->and(davRequest($this->other, 'GET', $this->addressBook.'secret.vcf')->getStatus())->toBe(403);
    });

    it('returns no property values from PROPFIND on another user\'s collections', function (string $path) {
        $response = davRequest($this->other, 'PROPFIND', str_replace('{owner}', $this->owner->email, $path), null, ['Depth' => '1']);
        $xpath = davXpath($response);

        // The scheduling inbox's resourcetype stays readable for scheduling.
        expect($xpath->evaluate('count(//d:response[not(contains(d:href, "/inbox/"))]/d:propstat[contains(d:status, "200")])'))->toBe(0.0)
            ->and($response->getBodyAsString())->not->toContain('Secret');
    })->with([
        'calendar home' => 'calendars/{owner}/',
        'calendar' => 'calendars/{owner}/my-calendar/',
        'address book home' => 'addressbooks/{owner}/',
        'address book' => 'addressbooks/{owner}/contacts/',
        'principal' => 'principals/{owner}/',
    ]);

    it('returns no data from REPORTs on another user\'s collections', function (string $collection, string $body) {
        $path = str_replace('{owner}', $this->owner->email, $collection);
        $body = str_replace('{owner}', $this->owner->email, $body);

        $response = davRequest($this->other, 'REPORT', $path, $body, ['Depth' => '1']);

        expect(davXpath($response)->evaluate('count(//d:propstat[contains(d:status, "200")])'))->toBe(0.0)
            ->and($response->getBodyAsString())->not->toContain('Secret');
    })->with([
        'calendar-query' => ['calendars/{owner}/my-calendar/', '<?xml version="1.0"?><cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><cal:calendar-data/></d:prop><cal:filter><cal:comp-filter name="VCALENDAR"/></cal:filter></cal:calendar-query>'],
        'calendar-multiget' => ['calendars/{owner}/my-calendar/', '<?xml version="1.0"?><cal:calendar-multiget xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:prop><cal:calendar-data/></d:prop><d:href>/calendars/{owner}/my-calendar/secret.ics</d:href></cal:calendar-multiget>'],
        'calendar sync-collection' => ['calendars/{owner}/my-calendar/', '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>'],
        'addressbook-query' => ['addressbooks/{owner}/contacts/', '<?xml version="1.0"?><card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><card:address-data/></d:prop></card:addressbook-query>'],
        'addressbook-multiget' => ['addressbooks/{owner}/contacts/', '<?xml version="1.0"?><card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><card:address-data/></d:prop><d:href>/addressbooks/{owner}/contacts/secret.vcf</d:href></card:addressbook-multiget>'],
    ]);

    it('denies free-busy-query on another user\'s calendar', function () {
        $response = davRequest($this->other, 'REPORT', $this->calendar, '<?xml version="1.0"?>'
            .'<cal:free-busy-query xmlns:cal="urn:ietf:params:xml:ns:caldav"><cal:time-range start="20301015T000000Z" end="20301016T000000Z"/></cal:free-busy-query>', ['Depth' => '1']);

        expect($response->getStatus())->toBe(403)
            ->and($response->getBodyAsString())->not->toContain('FREEBUSY');
    });

    it('hides other users from the principal and collection listings', function () {
        $principals = davRequest($this->other, 'PROPFIND', 'principals/', null, ['Depth' => '1'])->getBodyAsString();
        $calendar = davRequest($this->other, 'PROPFIND', $this->calendar, null, ['Depth' => '1'])->getBodyAsString();

        expect($principals)->not->toContain($this->owner->email)
            ->and($calendar)->not->toContain('secret.ics');
    });

    it('finds nobody with a principal search by email', function () {
        $response = davRequest($this->other, 'REPORT', 'principals/', '<?xml version="1.0"?>'
            .'<d:principal-property-search xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><d:property-search><d:prop><s:email-address/></d:prop>'
            .'<d:match>'.$this->owner->email.'</d:match></d:property-search><d:prop><d:displayname/></d:prop></d:principal-property-search>', ['Depth' => '0']);

        expect($response->getStatus())->toBe(207)
            ->and(davXpath($response)->evaluate('count(//d:response)'))->toBe(0.0);
    });

    // Accepted (owner decision, 2026-10-17): fixing this needs principal URIs
    // without the email, which means migrating the DAV data and every client.
    it('still tells an existing principal from a missing one', function () {
        expect(davRequest($this->other, 'PROPFIND', 'principals/'.$this->owner->email.'/', null, ['Depth' => '0'])->getStatus())->toBe(207)
            ->and(davRequest($this->other, 'PROPFIND', 'principals/nobody@example.org/', null, ['Depth' => '0'])->getStatus())->toBe(404);
    });
});

describe('DAV isolation: writing another user\'s data', function () {
    it('denies writes to another user\'s calendar', function (string $method, string $target, ?string $body, array $headers) {
        $path = str_replace('{owner}', $this->owner->email, $target);

        expect(davRequest($this->other, $method, $path, $body, $headers)->getStatus())->toBe(403);
    })->with([
        'overwrite an event' => ['PUT', 'calendars/{owner}/my-calendar/secret.ics', davIcs('secret', 'Hacked'), ['Content-Type' => 'text/calendar']],
        'create an event' => ['PUT', 'calendars/{owner}/my-calendar/new.ics', davIcs('new'), ['Content-Type' => 'text/calendar']],
        'delete an event' => ['DELETE', 'calendars/{owner}/my-calendar/secret.ics', null, []],
        'change properties' => ['PROPPATCH', 'calendars/{owner}/my-calendar/', '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:displayname>Hacked</d:displayname></d:prop></d:set></d:propertyupdate>', []],
        'create a calendar' => ['MKCALENDAR', 'calendars/{owner}/intruder/', null, []],
        'delete the calendar' => ['DELETE', 'calendars/{owner}/my-calendar/', null, []],
    ]);

    it('denies writes to another user\'s address book', function (string $method, string $target, ?string $body, array $headers) {
        $path = str_replace('{owner}', $this->owner->email, $target);

        expect(davRequest($this->other, $method, $path, $body, $headers)->getStatus())->toBe(403);
    })->with([
        'overwrite a card' => ['PUT', 'addressbooks/{owner}/contacts/secret.vcf', davVcard('secret', 'Hacked'), ['Content-Type' => 'text/vcard']],
        'create a card' => ['PUT', 'addressbooks/{owner}/contacts/new.vcf', davVcard('new'), ['Content-Type' => 'text/vcard']],
        'delete a card' => ['DELETE', 'addressbooks/{owner}/contacts/secret.vcf', null, []],
        'delete the address book' => ['DELETE', 'addressbooks/{owner}/contacts/', null, []],
    ]);

    it('denies moving and copying across users in both directions', function () {
        $own = 'calendars/'.$this->other->email.'/my-calendar/';
        davRequest($this->other, 'PUT', $own.'mine.ics', davIcs('mine'), ['Content-Type' => 'text/calendar']);

        expect(davRequest($this->other, 'MOVE', $own.'mine.ics', null, ['Destination' => '/'.$this->calendar.'mine.ics'])->getStatus())->toBe(403)
            ->and(davRequest($this->other, 'COPY', $this->calendar.'secret.ics', null, ['Destination' => '/'.$own.'stolen.ics'])->getStatus())->toBe(403)
            ->and(davRequest($this->other, 'MOVE', $this->calendar.'secret.ics', null, ['Destination' => '/'.$own.'stolen.ics'])->getStatus())->toBe(403)
            ->and(davRequest($this->owner, 'GET', $this->calendar.'secret.ics')->getStatus())->toBe(200);
    });

    it('leaves the owner\'s data untouched after the attempts', function () {
        davRequest($this->other, 'PUT', $this->calendar.'secret.ics', davIcs('secret', 'Hacked'), ['Content-Type' => 'text/calendar']);
        davRequest($this->other, 'DELETE', $this->addressBook.'secret.vcf');

        expect(davRequest($this->owner, 'GET', $this->calendar.'secret.ics')->getBodyAsString())->toContain('SUMMARY:Secret meeting')
            ->and(davRequest($this->owner, 'GET', $this->addressBook.'secret.vcf')->getStatus())->toBe(200);
    });
});

describe('DAV calendar sharing', function () {
    it('lets a read-write sharee read, add and change events', function () {
        $shared = shareCalendar($this->owner, $this->other, true);

        expect(davRequest($this->other, 'GET', $shared.'secret.ics')->getBodyAsString())->toContain('SUMMARY:Secret meeting')
            ->and(davRequest($this->other, 'PUT', $shared.'added.ics', davIcs('added', 'From sharee'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(201)
            ->and(davRequest($this->other, 'PUT', $shared.'secret.ics', davIcs('secret', 'Edited'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(204)
            ->and(davRequest($this->owner, 'GET', $this->calendar.'added.ics')->getBodyAsString())->toContain('SUMMARY:From sharee')
            ->and(davRequest($this->owner, 'GET', $this->calendar.'secret.ics')->getBodyAsString())->toContain('SUMMARY:Edited');
    });

    it('lets a read-only sharee read but not write', function () {
        $shared = shareCalendar($this->owner, $this->other, false);

        expect(davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus())->toBe(200)
            ->and(davRequest($this->other, 'PUT', $shared.'added.ics', davIcs('added'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(403)
            ->and(davRequest($this->other, 'PUT', $shared.'secret.ics', davIcs('secret', 'Edited'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(403)
            ->and(davRequest($this->other, 'DELETE', $shared.'secret.ics')->getStatus())->toBe(403);
    });

    it('lets an accepted sharee query free/busy on the shared calendar', function () {
        $shared = shareCalendar($this->owner, $this->other, false);

        $response = davRequest($this->other, 'REPORT', $shared, '<?xml version="1.0"?>'
            .'<cal:free-busy-query xmlns:cal="urn:ietf:params:xml:ns:caldav"><cal:time-range start="20301015T000000Z" end="20301016T000000Z"/></cal:free-busy-query>', ['Depth' => '1']);

        expect($response->getStatus())->toBe(200)
            ->and($response->getBodyAsString())->toContain('FREEBUSY:20301015T100000Z/20301015T110000Z');
    });

    it('marks a read-only share as read-only in PROPFIND', function () {
        $shared = shareCalendar($this->owner, $this->other, false);

        $xpath = davXpath(davRequest($this->other, 'PROPFIND', $shared, davPropfindBody(['d:share-access']), ['Depth' => '0']));

        expect($xpath->evaluate('count(//d:share-access/d:read)'))->toBe(1.0);
    });

    it('keeps the owner\'s own path closed to a sharee', function () {
        shareCalendar($this->owner, $this->other, true);

        expect(davRequest($this->other, 'GET', $this->calendar.'secret.ics')->getStatus())->toBe(403);
    });

    it('keeps an accepted share accepted when the calendar is shared again', function () {
        $shared = shareCalendar($this->owner, $this->other, true);
        $calendar = app(DavService::class)->calendars()->list($this->owner)[0];

        app(DavService::class)->calendars()->sharing()->inviteUser($calendar, $this->owner, $this->other);

        expect(app(DavService::class)->calendars()->sharing()->listAcceptedShares($this->other))->toHaveCount(1)
            ->and(davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus())->toBe(200);
    });

    it('keeps a pending share pending when the calendar is shared again', function () {
        shareCalendar($this->owner, $this->other, true, accept: false);
        $calendar = app(DavService::class)->calendars()->list($this->owner)[0];

        app(DavService::class)->calendars()->sharing()->inviteUser($calendar, $this->owner, $this->other);

        expect(app(DavService::class)->calendars()->sharing()->listInvites($this->other))->toHaveCount(1);
    });

    it('changes the access of an existing share without resetting it', function () {
        $shared = shareCalendar($this->owner, $this->other, true);
        $calendar = app(DavService::class)->calendars()->list($this->owner)[0];

        (new AppCalendarsPDO(DB::connection('pgsql')->getPdo()))->shareCalendarWithUserCustom($this->owner, $this->other, $calendar, false);

        expect(davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus())->toBe(200)
            ->and(davRequest($this->other, 'PUT', $shared.'added.ics', davIcs('added'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(403);
    });

    it('removes access when the share is revoked', function () {
        $shared = shareCalendar($this->owner, $this->other, true);
        $calendar = app(DavService::class)->calendars()->list($this->owner)[0];

        app(DavService::class)->calendars()->sharing()->revokeShare($calendar->calendarId, $this->other);

        expect(davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus())->toBe(404);
    });

    it('removes access when the invite is declined', function () {
        $shared = shareCalendar($this->owner, $this->other, true, accept: false);

        app(DavService::class)->calendars()->sharing()->declineInvite('mailto:'.$this->other->email);

        expect(davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus())->toBe(404);
    });

    it('lists an accepted share in the sharee\'s calendar home', function () {
        shareCalendar($this->owner, $this->other, false);

        $home = davRequest($this->other, 'PROPFIND', 'calendars/'.$this->other->email.'/', davPropfindBody(['d:displayname']), ['Depth' => '1']);

        expect($home->getBodyAsString())->toContain(sharedInstanceUri($this->other));
    });

    it('keeps a pending share out of the sharee\'s calendar home', function () {
        shareCalendar($this->owner, $this->other, true, accept: false);

        $home = davRequest($this->other, 'PROPFIND', 'calendars/'.$this->other->email.'/', davPropfindBody(['d:displayname']), ['Depth' => '1']);

        expect($home->getBodyAsString())->not->toContain(sharedInstanceUri($this->other));
    });

    it('still shows a pending share as an invite in the app', function () {
        shareCalendar($this->owner, $this->other, true, accept: false);

        expect(app(DavService::class)->calendars()->sharing()->listInvites($this->other))->toHaveCount(1);
    });

    it('turns a share made over DAV into an invite the sharee accepts in the app', function () {
        $response = davRequest($this->owner, 'POST', $this->calendar, '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:share-resource xmlns:d="DAV:"><d:sharee><d:href>mailto:'.$this->other->email.'</d:href>'
            .'<d:share-access><d:read-write/></d:share-access></d:sharee></d:share-resource>', ['Content-Type' => 'application/davsharing+xml; charset=utf-8']);
        $shared = 'calendars/'.$this->other->email.'/'.sharedInstanceUri($this->other).'/';
        $sharing = app(DavService::class)->calendars()->sharing();

        $beforeAccept = davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus();
        $sharing->acceptInvite('mailto:'.$this->other->email);

        expect($response->getStatus())->toBeLessThan(300)
            ->and($beforeAccept)->toBe(404)
            ->and(davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus())->toBe(200);
    });

    it('grants no access before the invite is accepted', function () {
        $shared = shareCalendar($this->owner, $this->other, true, accept: false);

        expect(davRequest($this->other, 'GET', $shared.'secret.ics')->getStatus())->toBe(404)
            ->and(davRequest($this->other, 'PUT', $shared.'early.ics', davIcs('early'), ['Content-Type' => 'text/calendar'])->getStatus())->toBe(404);
    });
});
