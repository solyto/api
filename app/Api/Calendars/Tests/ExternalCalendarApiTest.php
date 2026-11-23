<?php

use App\Api\Todos\Models\Todo;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeCalDavServer;

beforeEach(function () {
    $this->remote = (new FakeCalDavServer())->fake();
    $this->user = makeUser();
    $this->calendar = externalCalendar($this->user, $this->remote);
});

function remoteEventIcs(string $uid, string $summary, string $start, string $end): string
{
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Remote//EN', 'BEGIN:VEVENT',
        "UID:{$uid}", 'DTSTAMP:20300101T000000Z', "DTSTART:{$start}", "DTEND:{$end}", "SUMMARY:{$summary}",
        'END:VEVENT', 'END:VCALENDAR',
    ])."\r\n";
}

function remoteEventStart(int $days = 1): string
{
    return now('UTC')->addDays($days)->setTime(10, 0)->format('Ymd\THis\Z');
}

function remoteEventEnd(int $days = 1): string
{
    return now('UTC')->addDays($days)->setTime(11, 0)->format('Ymd\THis\Z');
}

describe('External calendars through the API', function () {
    it('lists the external calendar like a native one, flagged', function () {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->assertStatus(200);
        $calendars = collect($response->json('data'))->keyBy('name');

        expect($calendars['Remote']['is_external'])->toBeTrue()
            ->and($calendars['Remote']['is_unavailable'])->toBeFalse()
            ->and($calendars['My Calendar']['is_external'])->toBeFalse();
    });

    it('lists remote events with a stable id and an empty unavailable list', function () {
        // Mid-month, so tomorrow's event is listed under the current month.
        $this->travelTo('2026-11-10 12:00:00');
        $this->remote->put('a.ics', remoteEventIcs('a', 'Remote meeting', remoteEventStart(), remoteEventEnd()));

        $first = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/'.now()->format('Y-m'))->assertStatus(200);
        $event = collect($first->json('data'))->firstWhere('title', 'Remote meeting');

        expect($event)->not->toBeNull()
            ->and($event['uri'])->toBe('a.ics')
            ->and((int) $event['calendar_id'])->toBe($this->calendar->instanceId)
            ->and($event['id'])->toBeGreaterThanOrEqual(2 ** 52)
            ->and($event['id'])->toBeLessThan(2 ** 53)
            ->and($first->json('meta.unavailable_calendars'))->toBe([]);

        $this->travel(10)->minutes();
        $again = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/'.now()->format('Y-m'));

        expect(collect($again->json('data'))->firstWhere('title', 'Remote meeting')['id'])->toBe($event['id']);
    });

    it('creates, updates and deletes an event on the remote with no local copy', function () {
        $start = now()->addDay()->setTime(10, 0);

        $store = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events', [
            'title' => 'Created via API',
            'start_date' => $start->format('Y-m-d H:i:s'),
            'end_date' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $this->calendar->instanceId,
        ])->assertStatus(201);

        $uri = $store->json('data.uri');

        expect($this->remote->objects[$uri]['data'])->toContain('SUMMARY:Created via API')
            ->and($store->json('data.etag'))->toBe($this->remote->objects[$uri]['etag']);

        $update = $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/'.$uri, [
            'title' => 'Updated via API',
            'start_date' => $start->format('Y-m-d H:i:s'),
            'end_date' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $this->calendar->instanceId,
            'etag' => $store->json('data.etag'),
        ])->assertStatus(200);

        expect($this->remote->objects[$uri]['data'])->toContain('SUMMARY:Updated via API')
            ->and($update->json('data.title'))->toBe('Updated via API');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/'.$uri)
            ->assertStatus(200);

        expect($this->remote->objects)->toBeEmpty()
            ->and(DB::connection('pgsql')->table('calendarobjects')->where('calendarid', $this->calendar->calendarId)->count())->toBe(0);
    });

    it('returns the remote 412 when the event changed on its server', function () {
        $stale = $this->remote->put('a.ics', remoteEventIcs('a', 'Old', remoteEventStart(), remoteEventEnd()));
        $this->remote->put('a.ics', remoteEventIcs('a', 'Changed elsewhere', remoteEventStart(), remoteEventEnd()));
        $start = now()->addDay()->setTime(10, 0);

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics', [
            'title' => 'Mine',
            'start_date' => $start->format('Y-m-d H:i:s'),
            'end_date' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $this->calendar->instanceId,
            'etag' => $stale,
        ]);

        expectApiError($response, 412);
        $response->assertJsonPath('errors', ['external_event_changed']);
        expect($this->remote->objects['a.ics']['data'])->toContain('Changed elsewhere');
    });

    it('drops the cached events of the calendar on a remote 412 or an unreadable event', function (string $case) {
        $start = now('UTC')->addDay()->setTime(10, 0);
        $cache = Mockery::mock(new \App\Shared\Services\UserCacheService());
        $this->instance(\App\Shared\Services\UserCacheService::class, $cache);

        if ($case === '412') {
            $stale = $this->remote->put('a.ics', remoteEventIcs('a', 'Old', remoteEventStart(), remoteEventEnd()));
            $this->remote->put('a.ics', remoteEventIcs('a', 'Changed elsewhere', remoteEventStart(), remoteEventEnd()));
            $url = '/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics';
        } else {
            $stale = $this->remote->put('a.ics', implode("\r\n", [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Remote//EN', 'BEGIN:VEVENT', 'UID:a', 'DTSTAMP:20300101T000000Z',
                'DTSTART:'.$start->format('Ymd\THis\Z'), 'DTEND:'.$start->copy()->addHour()->format('Ymd\THis\Z'),
                'RRULE:FREQ=DAILY;COUNT=5', 'SUMMARY:Broken', 'not a content line', 'END:VEVENT', 'END:VCALENDAR',
            ])."\r\n");
            $url = '/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics/occurrence/'.$start->copy()->addDay()->format('Y-m-d H:i:s');
        }

        $response = $this->actingAs($this->user, 'sanctum')->putJson($url, [
            'title' => 'Mine',
            'start_date' => $start->copy()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => $start->copy()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $this->calendar->instanceId,
            'etag' => $stale,
        ]);

        expectApiError($response, $case === '412' ? 412 : 422);
        $cache->shouldHaveReceived('flushTags')->with([\App\Api\Calendars\Services\CalendarService::eventCacheTag($this->user->id, $this->calendar->calendarId)]);
    })->with(['412', 'unreadable']);

    it('shows the remote\'s version on the next load after a remote 412 or an unreadable event', function (string $case) {
        $start = now('UTC')->addDay()->setTime(10, 0);
        $series = fn (string $summary, array $extra = []) => implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Remote//EN', 'BEGIN:VEVENT', 'UID:a', 'DTSTAMP:20300101T000000Z',
            'DTSTART:'.$start->format('Ymd\THis\Z'), 'DTEND:'.$start->copy()->addHour()->format('Ymd\THis\Z'),
            'RRULE:FREQ=DAILY;COUNT=2', "SUMMARY:{$summary}", ...$extra, 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n";
        $titles = fn () => collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200)->json('data'))->pluck('title')->all();

        $stale = $this->remote->put('a.ics', $series('Old'));
        expect($titles())->toBe(['Old', 'Old']);

        if ($case === '412') {
            $this->remote->put('a.ics', $series('Changed elsewhere'));
            $url = '/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics';
        } else {
            $this->remote->put('a.ics', $series('Changed elsewhere', ['not a content line']));
            $stale = $this->remote->objects['a.ics']['etag'];
            $url = '/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics/occurrence/'.$start->copy()->addDay()->format('Y-m-d H:i:s');
        }
        expect($titles())->toBe(['Old', 'Old']);

        $response = $this->actingAs($this->user, 'sanctum')->putJson($url, [
            'title' => 'Mine',
            'start_date' => $start->copy()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => $start->copy()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $this->calendar->instanceId,
            'etag' => $stale,
        ]);

        expectApiError($response, $case === '412' ? 412 : 422);
        // The listing skips an object it cannot read.
        expect($titles())->toBe($case === '412' ? ['Changed elsewhere', 'Changed elsewhere'] : []);
    })->with(['412', 'unreadable']);

    it('leaves an unreachable calendar out of listings and reports it', function () {
        $this->remote->down = true;
        $start = now()->addDay()->setTime(10, 0);
        $native = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'My Calendar');
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars/'.$native['id'].'/events', [
            'title' => 'Native event',
            'start_date' => $start->format('Y-m-d H:i:s'),
            'end_date' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $native['id'],
        ])->assertStatus(201);

        $widget = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);
        $yourDay = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/widgets/your-day')->assertStatus(200);
        $calendars = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->keyBy('name');

        expect($widget->json('meta.unavailable_calendars'))->toBe([$this->calendar->instanceId])
            ->and(collect($widget->json('data'))->pluck('title')->all())->toBe(['Native event'])
            ->and($yourDay->json('meta.unavailable_calendars'))->toBe([$this->calendar->instanceId])
            ->and($calendars['Remote']['is_unavailable'])->toBeTrue();

        $this->remote->down = false;
        $this->travel(301)->seconds();
        $recovered = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget');

        expect($recovered->json('meta.unavailable_calendars'))->toBe([])
            ->and(collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Remote')['is_unavailable'])->toBeFalse();
    });

    it('tells a server that rejects the app password apart from an unreachable one', function () {
        $this->remote->credentials = ['alice', 'changed-on-the-server'];

        $events = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);
        $yourDay = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/widgets/your-day')->assertStatus(200);
        $calendar = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Remote');

        expect($events->json('meta.unavailable_calendars'))->toBe([$this->calendar->instanceId])
            ->and($events->json('meta.credentials_rejected_calendars'))->toBe([$this->calendar->instanceId])
            ->and($yourDay->json('meta.credentials_rejected_calendars'))->toBe([$this->calendar->instanceId])
            ->and($calendar['is_unavailable'])->toBeTrue()
            ->and($calendar['is_credentials_rejected'])->toBeTrue();
    });

    it('reports an unreachable server without blaming the credentials', function () {
        $this->remote->down = true;

        $events = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);
        $calendar = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Remote');

        expect($events->json('meta.unavailable_calendars'))->toBe([$this->calendar->instanceId])
            ->and($events->json('meta.credentials_rejected_calendars'))->toBe([])
            ->and($calendar['is_unavailable'])->toBeTrue()
            ->and($calendar['is_credentials_rejected'])->toBeFalse();
    });

    it('reports rejected credentials a DAV client ran into', function () {
        $user = davUser();
        $calendar = externalCalendar($user, $this->remote);
        $this->remote->credentials = ['alice', 'changed-on-the-server'];

        davRequest($user, 'PROPFIND', 'calendars/'.$user->email.'/', davPropfindBody(['cs:getctag']), ['Depth' => '1']);
        $listed = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('id', $calendar->instanceId);

        expect($listed['is_unavailable'])->toBeTrue()
            ->and($listed['is_credentials_rejected'])->toBeTrue();
    });

    it('takes the copy back when moving out of an external calendar fails on the remote delete', function () {
        $etag = $this->remote->put('a.ics', remoteEventIcs('a', 'Remote', remoteEventStart(), remoteEventEnd()));
        $this->remote->failDelete = true;
        $native = app(\App\Dav\Services\DavService::class)->calendars()->getByName($this->user, 'My Calendar');
        $cache = Mockery::mock(new \App\Shared\Services\UserCacheService());
        $this->instance(\App\Shared\Services\UserCacheService::class, $cache);

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics', [
            'title' => 'Moved',
            'start_date' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $native->instanceId,
            'etag' => $etag,
        ]);

        expectApiError($response, 503);
        expect(DB::connection('pgsql')->table('calendarobjects')->where('calendarid', $native->calendarId)->count())->toBe(0)
            ->and($this->remote->objects)->toHaveKey('a.ics');
        $cache->shouldHaveReceived('flushTags')->with([\App\Api\Calendars\Services\CalendarService::eventCacheTag($this->user->id, $this->calendar->calendarId)]);
        $cache->shouldHaveReceived('flushTags')->with([\App\Api\Calendars\Services\CalendarService::eventCacheTag($this->user->id, $native->calendarId)]);
    });

    it('does not ask a remote that just failed again for the unavailable TTL', function () {
        config(['calendars.external.unavailable_ttl' => 300]);
        $this->remote->down = true;
        $listing = fn () => $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/'.now()->format('Y-m'));

        $listing()->assertStatus(200);
        $asked = count($this->remote->requests);
        $again = $listing()->assertStatus(200);

        expect($asked)->toBeGreaterThan(0)
            ->and($this->remote->requests)->toHaveCount($asked)
            ->and($again->json('meta.unavailable_calendars'))->toBe([$this->calendar->instanceId])
            ->and(collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Remote')['is_unavailable'])->toBeTrue();

        $this->travel(301)->seconds();
        $listing();

        expect(count($this->remote->requests))->toBeGreaterThan($asked);
    });

    it('still serves cached events of a remote flagged unavailable without asking it', function () {
        $this->remote->put('a.ics', remoteEventIcs('a', 'Cached', remoteEventStart(), remoteEventEnd()));
        $widget = fn () => $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);
        $widget();

        $this->remote->down = true;
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/'.now()->addYear()->format('Y-m'))->assertStatus(200);
        $asked = count($this->remote->requests);
        $again = $widget();

        expect(collect($again->json('data'))->pluck('title')->all())->toBe(['Cached'])
            ->and($again->json('meta.unavailable_calendars'))->toBe([$this->calendar->instanceId])
            ->and($this->remote->requests)->toHaveCount($asked);
    });

    it('never sends a dot-segment event name to the remote', function (string $method, string $name) {
        $this->remote->put('a.ics', remoteEventIcs('a', 'Remote', remoteEventStart(), remoteEventEnd()));
        $url = '/api/v1/calendars/'.$this->calendar->instanceId.'/events/'.$name;

        $response = $method === 'DELETE'
            ? $this->actingAs($this->user, 'sanctum')->deleteJson($url)
            : $this->actingAs($this->user, 'sanctum')->putJson($url, [
                'title' => 'X',
                'start_date' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $this->calendar->instanceId,
                'etag' => '"x"',
            ]);

        expectApiError($response, 404);
        expect($this->remote->requests)->toBeEmpty()
            ->and($this->remote->objects)->toHaveKey('a.ics');
    })->with(['DELETE', 'PUT'])->with(['%2E', '%2E%2E', '.%2E']);

    it('answers 422 for an occurrence of a remote series that cannot be parsed', function (string $method) {
        $start = now('UTC')->addDay()->setTime(10, 0);
        $this->remote->put('broken.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Remote//EN', 'BEGIN:VEVENT', 'UID:broken', 'DTSTAMP:20300101T000000Z',
            'DTSTART:'.$start->format('Ymd\THis\Z'), 'DTEND:'.$start->copy()->addHour()->format('Ymd\THis\Z'),
            'RRULE:FREQ=DAILY;COUNT=5', 'SUMMARY:Broken', 'not a content line', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n");
        $url = '/api/v1/calendars/'.$this->calendar->instanceId.'/events/broken.ics/occurrence/'.$start->copy()->addDay()->format('Y-m-d H:i:s');

        $response = $method === 'DELETE'
            ? $this->actingAs($this->user, 'sanctum')->deleteJson($url)
            : $this->actingAs($this->user, 'sanctum')->putJson($url, [
                'title' => 'Changed',
                'start_date' => $start->copy()->addDay()->setTime(12, 0)->format('Y-m-d H:i:s'),
                'end_date' => $start->copy()->addDay()->setTime(13, 0)->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $this->calendar->instanceId,
                'etag' => $this->remote->objects['broken.ics']['etag'],
            ]);

        expectApiError($response, 422);
        $response->assertJsonPath('errors', ['external_event_unreadable']);
        expect(collect($this->remote->requests)->filter(fn ($r) => $r->method() === 'PUT'))->toBeEmpty();
    })->with(['DELETE', 'PUT']);

    it('answers 503 for a single event of an unreachable calendar', function () {
        $this->remote->down = true;

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics');

        expectApiError($response, 503);
        $response->assertJsonPath('errors', ['external_server_unavailable']);
    });

    it('caches external events for the short TTL only', function () {
        config(['calendars.external.cache_ttl' => 300]);
        $this->remote->put('a.ics', remoteEventIcs('a', 'Before', remoteEventStart(), remoteEventEnd()));
        $titles = fn () => collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->json('data'))->pluck('title')->all();

        expect($titles())->toBe(['Before']);

        $this->remote->put('a.ics', remoteEventIcs('a', 'After', remoteEventStart(), remoteEventEnd()));

        expect($titles())->toBe(['Before']);

        $this->travel(301)->seconds();

        expect($titles())->toBe(['After']);
    });

    it('caches an empty external window', function () {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);

        expect(collect($this->remote->requests)->filter(fn ($r) => $r->method() === 'REPORT'))->toHaveCount(1);
    });

    it('skips remote objects that cannot be read instead of failing the listing', function () {
        $this->remote->put('good.ics', remoteEventIcs('good', 'Good', remoteEventStart(), remoteEventEnd()));
        $this->remote->put('garbage.ics', 'garbage');
        $this->remote->put('nostart.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Remote//EN', 'BEGIN:VEVENT',
            'UID:nostart', 'DTSTAMP:20300101T000000Z', 'SUMMARY:No start', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n");

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);

        expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Good'])
            ->and($response->json('meta.unavailable_calendars'))->toBe([]);
    });

    it('attaches a todo to an external event and removes it with the event', function () {
        $this->remote->put('a.ics', remoteEventIcs('a', 'Remote', remoteEventStart(), remoteEventEnd()));
        $event = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->json('data'))->firstWhere('uri', 'a.ics');
        $todo = Todo::factory()->forUser($this->user)->create();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/calendars/events/'.$event['id'].'/attachments/todos', ['todo_id' => $todo->id])
            ->assertStatus(201);
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.$event['id'].'/attachments/todos')
            ->assertJsonPath('data.0.id', $todo->id);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics')
            ->assertStatus(200);

        expect(DB::table('calendar_event_todos')->count())->toBe(0);
    });

    it('deletes one occurrence of a remote series with the ETag it read', function () {
        $start = now('UTC')->addDay()->setTime(10, 0);
        $etag = $this->remote->put('series.ics', implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Remote//EN', 'BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20300101T000000Z',
            'DTSTART:'.$start->format('Ymd\THis\Z'), 'DTEND:'.$start->copy()->addHour()->format('Ymd\THis\Z'),
            'RRULE:FREQ=DAILY;COUNT=5', 'SUMMARY:Daily', 'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n");

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/series.ics/occurrence/'.$start->copy()->addDay()->format('Y-m-d H:i:s'))
            ->assertStatus(200);

        expect($this->remote->objects['series.ics']['data'])->toContain('EXDATE')
            ->and(collect($this->remote->requests)->firstWhere(fn ($r) => $r->method() === 'PUT')->header('If-Match'))->toBe([$etag]);
    });

    it('refuses to share an external calendar', function () {
        $friend = makeUser();
        \App\Api\Users\Models\Friend::factory()->forUsers($this->user, $friend)->create();

        expectApiError($this->actingAs($this->user, 'sanctum')->postJson('/api/v1/calendars/'.$this->calendar->instanceId.'/share', ['friend_id' => $friend->id]), 403);
        expectApiError($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/'.$this->calendar->instanceId.'/share'), 403);
        expectApiError($this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/calendars/'.$this->calendar->instanceId.'/share/'.$friend->id), 403);
    });

    it('removes the link and the local row, never the remote calendar', function () {
        $this->remote->put('a.ics', remoteEventIcs('a', 'Remote', remoteEventStart(), remoteEventEnd()));

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/calendars/'.$this->calendar->instanceId)->assertStatus(200);

        expect(\App\Api\Calendars\Models\ExternalCalendar::count())->toBe(0)
            ->and($this->remote->objects)->toHaveKey('a.ics')
            ->and(collect($this->remote->requests)->filter(fn ($r) => $r->method() === 'DELETE'))->toBeEmpty();
    });
});

describe('Connecting external calendars', function () {
    it('adds an account, lists its calendars and connects one', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendars/external/accounts', [
            'server_url' => FakeCalDavServer::ORIGIN,
            'username' => 'alice',
            'password' => 'app-password',
        ])->assertStatus(201);

        expect($store->json('data.username'))->toBe('alice')
            ->and($store->json('data'))->not->toHaveKey('password')
            ->and($store->json('data.calendars'))->toBe([]);

        $accountId = $store->json('data.id');
        $available = $this->actingAs($user, 'sanctum')->getJson("/api/v1/calendars/external/accounts/{$accountId}/available")->assertStatus(200);

        expect($available->json('data'))->toBe([
            ['url' => $this->remote->url(), 'name' => 'Work', 'color' => '#FF0000', 'is_connected' => false],
        ]);

        $connect = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$accountId}/calendars", [
            'urls' => [$this->remote->url()],
        ])->assertStatus(201);

        expect($connect->json('data.0.name'))->toBe('Work')
            ->and($connect->json('data.0.is_external'))->toBeTrue();

        $this->remote->put('a.ics', remoteEventIcs('a', 'From the server', remoteEventStart(), remoteEventEnd()));
        $events = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->json('data');

        expect(collect($events)->pluck('title')->all())->toBe(['From the server'])
            ->and($this->actingAs($user, 'sanctum')->getJson('/api/v1/calendars/external/accounts')->json('data.0.calendars.0.id'))->toBe($connect->json('data.0.id'));
    });

    it('makes the name unique and skips calendars already connected', function () {
        $user = makeUser();
        app(\App\Dav\Services\DavService::class)->calendars()->create($user, \App\Dav\DTOs\CalendarDTO::fromRequest(['name' => 'Work']));
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);

        $first = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", ['urls' => [$this->remote->url()]]);
        $second = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", ['urls' => [rtrim($this->remote->url(), '/')]]);

        expect($first->json('data.0.name'))->toBe('Work (1)')
            ->and($second->json('data'))->toBe([])
            ->and(\App\Api\Calendars\Models\ExternalCalendar::where('external_calendar_account_id', $account->id)->count())->toBe(1);
    });

    it('connects a calendar whose name slugs like a local one', function () {
        $user = makeUser();
        app(\App\Dav\Services\DavService::class)->calendars()->create($user, \App\Dav\DTOs\CalendarDTO::fromRequest(['name' => 'Work']));
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);
        $this->remote->displayName = 'work';

        $connect = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", ['urls' => [$this->remote->url()]])
            ->assertStatus(201);

        $uris = DB::connection('pgsql')->table('calendarinstances')->where('principaluri', 'principals/'.$user->email)->whereIn('displayname', ['Work', 'work'])->pluck('uri');

        expect($connect->json('data.0.name'))->toBe('work')
            ->and($uris->all())->toHaveCount(2)
            ->and($uris->unique()->all())->toHaveCount(2);
    });

    it('connects two calendars with emoji-only names', function () {
        $user = makeUser();
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);
        $this->remote->displayName = '🎉';
        $this->remote->siblings = ['/remote.php/dav/calendars/alice/party/' => '🎈'];

        $connect = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", [
            'urls' => [$this->remote->url(), FakeCalDavServer::ORIGIN.'/remote.php/dav/calendars/alice/party/'],
        ])->assertStatus(201);

        $uris = DB::connection('pgsql')->table('calendarinstances')->whereIn('displayname', ['🎉', '🎈'])->pluck('uri');

        expect($connect->json('data'))->toHaveCount(2)
            ->and($uris->filter()->unique()->all())->toHaveCount(2);
    });

    // SQLite does not enforce VARCHAR lengths, so the lengths pgsql enforces are asserted.
    it('fits long remote names and odd colours into the calendar columns', function () {
        $user = makeUser();
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);
        $this->remote->displayName = str_repeat('Щ', 150);
        $this->remote->siblings = ['/remote.php/dav/calendars/alice/party/' => str_repeat('Щ', 150)];
        $this->remote->color = 'rgba(255, 0, 0, 1)';

        $connect = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", [
            'urls' => [$this->remote->url(), FakeCalDavServer::ORIGIN.'/remote.php/dav/calendars/alice/party/'],
        ])->assertStatus(201);

        $rows = DB::connection('pgsql')->table('calendarinstances')->where('principaluri', 'principals/'.$user->email)->where('displayname', 'like', 'ЩЩ%')->get();

        expect($connect->json('data'))->toHaveCount(2)
            ->and($rows->pluck('displayname')->sort()->values()->all())->toBe([str_repeat('Щ', 96).' (1)', str_repeat('Щ', 100)])
            ->and($rows->pluck('calendarcolor')->unique()->all())->toBe(['#0088CC'])
            ->and($rows->pluck('uri')->unique())->toHaveCount(2)
            ->and($rows->map(fn ($row) => strlen($row->uri))->max())->toBeLessThanOrEqual(200);
    });

    it('keeps a short hex colour from the remote', function () {
        $user = makeUser();
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);
        $this->remote->color = '#FF0000FF';

        $connect = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", ['urls' => [$this->remote->url()]])
            ->assertStatus(201);

        expect($connect->json('data.0.color'))->toBe('#FF0000FF');
    });

    it('leaves nothing connected when a later calendar fails', function () {
        $user = makeUser();
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);
        $this->remote->siblings = ['/remote.php/dav/calendars/alice/home/' => 'Home'];
        $instances = DB::connection('pgsql')->table('calendarinstances')->count();

        $created = 0;
        \App\Api\Calendars\Models\ExternalCalendar::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('Second link fails');
            }
        });

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", [
            'urls' => [$this->remote->url(), FakeCalDavServer::ORIGIN.'/remote.php/dav/calendars/alice/home/'],
        ])->assertStatus(500);

        expect(\App\Api\Calendars\Models\ExternalCalendar::where('external_calendar_account_id', $account->id)->count())->toBe(0)
            ->and(DB::connection('pgsql')->table('calendarinstances')->count())->toBe($instances);
    });

    it('refuses URLs the server did not list', function () {
        $user = makeUser();
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);

        expectApiError($this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", [
            'urls' => ['https://dav.example.com/remote.php/dav/calendars/bob/private/'],
        ]), 422);
        expect(\App\Api\Calendars\Models\ExternalCalendar::where('external_calendar_account_id', $account->id)->count())->toBe(0);
    });

    it('refuses servers on private addresses and accounts without calendars', function (string $url, ?array $credentials, string $code) {
        $this->remote->credentials = $credentials;

        $response = $this->actingAs(makeUser(), 'sanctum')->postJson('/api/v1/calendars/external/accounts', [
            'server_url' => $url,
            'username' => 'alice',
            'password' => 'app-password',
        ]);

        expectApiError($response, 422);
        $response->assertJsonPath('errors', [$code]);
        expect(\App\Api\Calendars\Models\ExternalCalendarAccount::count())->toBe(1);
    })->with([
        'loopback' => ['http://127.0.0.1:8080', null, 'external_url_not_allowed'],
        'metadata' => ['http://169.254.169.254', null, 'external_url_not_allowed'],
        'private' => ['https://192.168.1.10', null, 'external_url_not_allowed'],
        'wrong password' => [FakeCalDavServer::ORIGIN, ['alice', 'other'], 'external_credentials_rejected'],
    ]);

    it('reports a server whose host does not resolve as unreachable, not as not allowed', function () {
        app()->instance(\App\Shared\Services\OutboundUrlGuard::class, new \App\Shared\Services\OutboundUrlGuard(fn () => []));

        $response = $this->actingAs(makeUser(), 'sanctum')->postJson('/api/v1/calendars/external/accounts', [
            'server_url' => 'https://nowhere.example',
            'username' => 'alice',
            'password' => 'app-password',
        ]);

        expectApiError($response, 422);
        $response->assertJsonPath('errors', ['external_server_unreachable']);
        expect($this->remote->requests)->toBeEmpty();
    });

    it('keeps a malformed server URL a field validation error', function () {
        $response = $this->actingAs(makeUser(), 'sanctum')->postJson('/api/v1/calendars/external/accounts', [
            'server_url' => 'not a url',
            'username' => 'alice',
        ]);

        expectApiError($response, 422);
        expect($response->json('errors'))->toHaveKeys(['server_url', 'password']);
        expect($this->remote->requests)->toBeEmpty();
    });

    it('tells rejected credentials, an unreachable server and a server without calendars apart', function (string $case, string $code, string $message) {
        match ($case) {
            'credentials' => $this->remote->credentials = ['alice', 'other'],
            'unreachable' => $this->remote->down = true,
            'empty' => null,
        };

        // Every discovery path below /nothing answers 404.
        $response = $this->actingAs(makeUser(), 'sanctum')->postJson('/api/v1/calendars/external/accounts', [
            'server_url' => FakeCalDavServer::ORIGIN.($case === 'empty' ? '/nothing' : ''),
            'username' => 'alice',
            'password' => 'app-password',
        ]);

        expectApiError($response, 422);
        $response->assertJsonPath('errors', [$code])->assertJsonPath('message', $message);
    })->with([
        'credentials rejected' => ['credentials', 'external_credentials_rejected', 'The server rejected the username or app password.'],
        'server unreachable' => ['unreachable', 'external_server_unreachable', 'The server could not be reached or did not answer as a CalDAV server.'],
        'no calendars' => ['empty', 'external_no_calendars', 'No calendars were found on the server.'],
    ]);

    it('reports a server that now rejects the app password when listing its calendars', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();
        $this->remote->credentials = ['alice', 'changed-on-the-server'];

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/calendars/external/accounts/{$account->id}/available");

        expectApiError($response, 422);
        $response->assertJsonPath('errors', ['external_credentials_rejected']);
    });

    it('throttles the routes that ask the server', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/calendars/external/accounts/{$account->id}/available")->assertStatus(200);
        }

        expectApiError($this->actingAs($this->user, 'sanctum')->getJson("/api/v1/calendars/external/accounts/{$account->id}/available"), 429);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/external/accounts')->assertStatus(200);
    });

    it('treats a concurrent connect of the same calendar as already connected', function () {
        $user = makeUser();
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);
        $instances = DB::connection('pgsql')->table('calendarinstances')->count();

        \App\Api\Calendars\Models\ExternalCalendar::creating(function () use ($account, $user) {
            DB::table('external_calendars')->insertOrIgnore([
                'external_calendar_account_id' => $account->id,
                'user_id' => $user->id,
                'remote_url' => $this->remote->url(),
                'remote_url_hash' => hash('sha256', $this->remote->url()),
                'calendar_id' => 999999,
                'instance_id' => 999999,
            ]);
        });

        $connect = $this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", ['urls' => [$this->remote->url()]])
            ->assertStatus(201);

        expect($connect->json('data'))->toBe([])
            ->and(\App\Api\Calendars\Models\ExternalCalendar::where('external_calendar_account_id', $account->id)->count())->toBe(1)
            ->and(DB::connection('pgsql')->table('calendarinstances')->count())->toBe($instances);
    });

    it('replaces the app password only after the server accepts it', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();
        $this->remote->credentials = ['alice', 'new-password'];

        $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/calendars/external/accounts/{$account->id}", ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['external_credentials_rejected']);
        expect($account->fresh()->password)->toBe('app-password');

        $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/calendars/external/accounts/{$account->id}", ['password' => 'new-password'])
            ->assertStatus(200)
            ->assertJsonMissingPath('data.password');
        expect($account->fresh()->password)->toBe('new-password');
    });

    it('asks the server again right away once the app password is fixed', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();
        $this->remote->put('a.ics', remoteEventIcs('a', 'Remote', remoteEventStart(), remoteEventEnd()));
        $this->remote->credentials = ['alice', 'new-password'];
        $widget = fn () => $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200);

        expect($widget()->json('meta.credentials_rejected_calendars'))->toBe([$this->calendar->instanceId]);

        $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/calendars/external/accounts/{$account->id}", ['password' => 'new-password'])
            ->assertStatus(200);
        $again = $widget();

        expect($again->json('meta.unavailable_calendars'))->toBe([])
            ->and(collect($again->json('data'))->pluck('title')->all())->toBe(['Remote'])
            ->and(collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Remote')['is_unavailable'])->toBeFalse();
    });

    it('shows the remote\'s current events right away once the app password is fixed', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();
        $this->remote->put('a.ics', remoteEventIcs('a', 'Before', remoteEventStart(), remoteEventEnd()));
        $titles = fn () => collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget')->assertStatus(200)->json('data'))->pluck('title')->all();
        expect($titles())->toBe(['Before']);

        $this->remote->put('a.ics', remoteEventIcs('a', 'After', remoteEventStart(), remoteEventEnd()));
        $this->remote->credentials = ['alice', 'new-password'];
        expect($titles())->toBe(['Before']);

        $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/calendars/external/accounts/{$account->id}", ['password' => 'new-password'])
            ->assertStatus(200);

        expect($titles())->toBe(['After']);
    });

    it('removes an account with its calendars, locally only', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();
        $this->remote->put('a.ics', remoteEventIcs('a', 'Remote', remoteEventStart(), remoteEventEnd()));

        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/calendars/external/accounts/{$account->id}")->assertStatus(200);

        $names = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->pluck('name');

        expect(\App\Api\Calendars\Models\ExternalCalendarAccount::count())->toBe(0)
            ->and(\App\Api\Calendars\Models\ExternalCalendar::count())->toBe(0)
            ->and($names->all())->not->toContain('Remote')
            ->and($this->remote->objects)->toHaveKey('a.ics')
            ->and(collect($this->remote->requests)->filter(fn ($r) => $r->method() === 'DELETE'))->toBeEmpty();
    });

    it('keeps other users away from an account', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();
        $other = makeUser();

        expectApiError($this->actingAs($other, 'sanctum')->getJson("/api/v1/calendars/external/accounts/{$account->id}/available"), 403);
        expectApiError($this->actingAs($other, 'sanctum')->putJson("/api/v1/calendars/external/accounts/{$account->id}", ['password' => 'x']), 403);
        expectApiError($this->actingAs($other, 'sanctum')->deleteJson("/api/v1/calendars/external/accounts/{$account->id}"), 403);
        expectApiError($this->actingAs($other, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", ['urls' => [$this->remote->url()]]), 403);
        expect($this->actingAs($other, 'sanctum')->getJson('/api/v1/calendars/external/accounts')->json('data'))->toBe([]);
    });
});

describe('Editing an external event the app cannot read', function () {
    beforeEach(function () {
        $this->remote = (new FakeCalDavServer())->fake();
        $this->user = makeUser();
        $this->calendar = externalCalendar($this->user, $this->remote);
    });

    it('cannot edit it and leaves the stored event as it was', function (string $stored) {
        $etag = $this->remote->put('broken.ics', $stored);
        $start = now()->addDay()->setTime(10, 0);

        $response = $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/broken.ics', [
            'title' => 'Edited in solyto',
            'start_date' => $start->format('Y-m-d H:i:s'),
            'end_date' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'is_all_day' => false,
            'calendar_id' => $this->calendar->instanceId,
            'etag' => $etag,
        ]);

        // The API can't load it as an event; toVCalForUpdate() refuses it too (EventDTOUpdateTest), should a path ever get there.
        expectApiError($response, 404);
        expect($this->remote->objects['broken.ics']['data'])->toBe($stored);
    })->with([
        'not iCalendar' => "BEGIN:VCALENDAR\r\nthis is not iCalendar\r\n",
        'only a changed occurrence' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//other//EN\r\nBEGIN:VEVENT\r\nUID:s\r\nDTSTAMP:20300101T000000Z\r\nRECURRENCE-ID:20301016T090000Z\r\nDTSTART:20301016T110000Z\r\nSUMMARY:Moved\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
    ]);
});
