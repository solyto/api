<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

describe('app:calendars:recompute-event-bounds', function () {
    beforeEach(function () {
        $user = davUser();
        foreach (['stale' => true, 'fine' => false] as $uid => $overrideFirst) {
            $response = davRequest($user, 'PUT', 'calendars/'.$user->email."/my-calendar/{$uid}.ics", boundsSeries($uid, $overrideFirst), ['Content-Type' => 'text/calendar']);
            expect($response->getStatus())->toBe(201);
        }

        // The bounds sabre stored for override-first objects before the fix.
        DB::connection('pgsql')->table('calendarobjects')->where('uri', 'stale.ics')->update([
            'firstoccurence' => 1894618800,
            'lastoccurence' => 1894622400,
        ]);

        $this->before = DB::connection('pgsql')->table('calendarobjects')->orderBy('uri')->get(['uri', 'etag', 'size', 'lastmodified', 'firstoccurence', 'lastoccurence']);
        $this->changes = DB::connection('pgsql')->table('calendarchanges')->count();
    });

    it('changes nothing on a dry run', function () {
        $this->artisan('app:calendars:recompute-event-bounds')
            ->expectsOutputToContain('Stale bounds: 1.')
            ->expectsOutputToContain('uri=stale.ics')
            ->assertSuccessful();

        expect(DB::connection('pgsql')->table('calendarobjects')->orderBy('uri')->get(['uri', 'etag', 'size', 'lastmodified', 'firstoccurence', 'lastoccurence']))
            ->toEqual($this->before);
    });

    it('rewrites only the bounds of stale objects with --apply', function () {
        $this->artisan('app:calendars:recompute-event-bounds', ['--apply' => true])
            ->expectsOutputToContain('Stale bounds: 1.')
            ->assertSuccessful();

        $after = DB::connection('pgsql')->table('calendarobjects')->orderBy('uri')->get(['uri', 'etag', 'size', 'lastmodified', 'firstoccurence', 'lastoccurence']);
        $stale = $after->firstWhere('uri', 'stale.ics');
        $staleBefore = $this->before->firstWhere('uri', 'stale.ics');

        expect($after->firstWhere('uri', 'fine.ics'))->toEqual($this->before->firstWhere('uri', 'fine.ics'))
            ->and((int) $stale->firstoccurence)->toBe(1894010400)
            ->and((int) $stale->lastoccurence)->toBe(2145916800)
            ->and([$stale->etag, $stale->size, $stale->lastmodified])->toBe([$staleBefore->etag, $staleBefore->size, $staleBefore->lastmodified])
            ->and(DB::connection('pgsql')->table('calendarchanges')->count())->toBe($this->changes);
    });
});
