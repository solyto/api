<?php

use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function boundsSeries(string $uid, bool $overrideFirst): string
{
    $master = [
        'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20300101T000000Z', 'DTSTART:20300107T100000Z', 'DTEND:20300107T110000Z',
        'RRULE:FREQ=WEEKLY', 'SUMMARY:Series', 'END:VEVENT',
    ];
    $override = [
        'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20300101T000000Z', 'RECURRENCE-ID:20300114T100000Z',
        'DTSTART:20300114T110000Z', 'DTEND:20300114T120000Z', 'SUMMARY:Series', 'END:VEVENT',
    ];

    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Solyto//Tests//EN',
        ...($overrideFirst ? [...$override, ...$master] : [...$master, ...$override]),
        'END:VCALENDAR',
    ])."\r\n";
}

function boundsRows(): \Illuminate\Support\Collection
{
    return DB::connection('pgsql')->table('calendarobjects')->orderBy('uri')
        ->get(['id', 'calendarid', 'uri', 'calendardata', 'etag', 'size', 'lastmodified', 'firstoccurence', 'lastoccurence'])
        ->keyBy('uri');
}

describe('recompute_event_occurrence_bounds migration', function () {
    beforeEach(function () {
        $this->owner = davUser();
        $this->sharee = davUser();
        $this->outsider = davUser();
        $put = fn ($user, string $uri, string $ics) => expect(davRequest($user, 'PUT', 'calendars/'.$user->email."/my-calendar/{$uri}", $ics, ['Content-Type' => 'text/calendar'])->getStatus())->toBe(201);

        $put($this->owner, 'stale.ics', boundsSeries('stale', overrideFirst: true));
        $put($this->owner, 'fine.ics', boundsSeries('fine', overrideFirst: false));
        $put($this->owner, 'plain.ics', davIcs('plain'));
        $put($this->owner, 'broken.ics', boundsSeries('broken', overrideFirst: true));
        $put($this->outsider, 'other.ics', boundsSeries('other', overrideFirst: false));

        $dav = app(DavService::class);
        $this->calendar = $dav->calendars()->list($this->owner)[0];
        $dav->calendars()->sharing()->inviteUser($this->calendar, $this->owner, $this->sharee);
        $dav->calendars()->sharing()->acceptInvite($this->sharee, 'mailto:'.$this->sharee->email);

        $objects = DB::connection('pgsql')->table('calendarobjects');
        // The bounds sabre stored for override-first objects before the fix.
        $objects->clone()->where('uri', 'stale.ics')->update(['firstoccurence' => 1894618800, 'lastoccurence' => 1894622400]);
        // Wrong too, but without an override the migration must not look at it.
        $objects->clone()->where('uri', 'plain.ics')->update(['firstoccurence' => 1, 'lastoccurence' => 2]);
        $objects->clone()->where('uri', 'broken.ics')->update(['calendardata' => "BEGIN:VCALENDAR\r\nRECURRENCE-ID:20300114T100000Z\r\nnot a content line\r\n"]);

        $this->before = boundsRows();
        $this->changes = DB::connection('pgsql')->table('calendarchanges')->count();

        $this->cache = Mockery::spy(UserCacheService::class);
        $this->app->instance(UserCacheService::class, $this->cache);
        $this->log = Mockery::spy();
        Log::shouldReceive('channel')->with('dav')->andReturn($this->log);

        (require database_path('migrations/2026_11_14_000002_recompute_event_occurrence_bounds.php'))->up();
        $this->after = boundsRows();
    });

    it('rewrites only the bounds of the stale row and logs the old values', function () {
        $stale = $this->after['stale.ics'];
        $staleBefore = $this->before['stale.ics'];

        expect((int) $stale->firstoccurence)->toBe(1894010400)
            ->and((int) $stale->lastoccurence)->toBe(2145916800)
            ->and([$stale->calendardata, $stale->etag, $stale->size, $stale->lastmodified])
            ->toBe([$staleBefore->calendardata, $staleBefore->etag, $staleBefore->size, $staleBefore->lastmodified])
            ->and(DB::connection('pgsql')->table('calendarchanges')->count())->toBe($this->changes);

        $this->log->shouldHaveReceived('warning')->with('Occurrence bounds migration: event bounds updated', Mockery::on(fn (array $context) => $context['id'] === $staleBefore->id
            && $context['uri'] === 'stale.ics'
            && (int) $context['calendarid'] === $this->calendar->calendarId
            && (int) $context['old']['firstoccurence'] === 1894618800
            && (int) $context['old']['lastoccurence'] === 1894622400
            && $context['new'] === ['firstoccurence' => 1894010400, 'lastoccurence' => 2145916800]))->once();
    });

    it('leaves correct rows, rows without an override and unparseable rows untouched', function () {
        foreach (['fine.ics', 'plain.ics', 'broken.ics', 'other.ics'] as $uri) {
            expect($this->after[$uri])->toEqual($this->before[$uri]);
        }

        $this->log->shouldHaveReceived('warning')->with('Occurrence bounds migration: event could not be parsed, left unchanged', Mockery::on(fn (array $context) => $context['uri'] === 'broken.ics'))->once();
        $this->log->shouldHaveReceived('warning')->twice();
    });

    it('forgets the event caches of the owner and sharees of changed calendars only', function () {
        foreach ([$this->owner, $this->sharee] as $member) {
            $this->cache->shouldHaveReceived('forgetByPrefix')->with(['calendar_events', $member->id, $this->calendar->calendarId])->once();
        }

        $this->cache->shouldHaveReceived('forgetByPrefix')->twice();
    });
});
