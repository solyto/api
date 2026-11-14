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
    })->skip(fn () => now()->addDay()->format('Y-m') !== now()->format('Y-m'), 'The event must fall into the current month.');

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
        expect($this->remote->objects['a.ics']['data'])->toContain('Changed elsewhere');
    });

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
        $recovered = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars/events/widget');

        expect($recovered->json('meta.unavailable_calendars'))->toBe([])
            ->and(collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Remote')['is_unavailable'])->toBeFalse();
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
        $cache->shouldHaveReceived('forgetByPrefix')->with(['calendar_events', $this->user->id, $this->calendar->calendarId]);
        $cache->shouldHaveReceived('forgetByPrefix')->with(['calendar_events', $this->user->id, $native->calendarId]);
    });

    it('answers 503 for a single event of an unreachable calendar', function () {
        $this->remote->down = true;

        expectApiError($this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/calendars/'.$this->calendar->instanceId.'/events/a.ics'), 503);
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

    it('refuses URLs the server did not list', function () {
        $user = makeUser();
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::factory()->forUser($user)->create(['server_url' => FakeCalDavServer::ORIGIN]);

        expectApiError($this->actingAs($user, 'sanctum')->postJson("/api/v1/calendars/external/accounts/{$account->id}/calendars", [
            'urls' => ['https://dav.example.com/remote.php/dav/calendars/bob/private/'],
        ]), 422);
        expect(\App\Api\Calendars\Models\ExternalCalendar::where('external_calendar_account_id', $account->id)->count())->toBe(0);
    });

    it('refuses servers on private addresses and accounts without calendars', function (string $url, ?array $credentials) {
        $this->remote->credentials = $credentials;

        expectApiError($this->actingAs(makeUser(), 'sanctum')->postJson('/api/v1/calendars/external/accounts', [
            'server_url' => $url,
            'username' => 'alice',
            'password' => 'app-password',
        ]), 422);
        expect(\App\Api\Calendars\Models\ExternalCalendarAccount::count())->toBe(1);
    })->with([
        'loopback' => ['http://127.0.0.1:8080', null],
        'metadata' => ['http://169.254.169.254', null],
        'private' => ['https://192.168.1.10', null],
        'wrong password' => [FakeCalDavServer::ORIGIN, ['alice', 'other']],
    ]);

    it('replaces the app password only after the server accepts it', function () {
        $account = \App\Api\Calendars\Models\ExternalCalendarAccount::where('user_id', $this->user->id)->first();
        $this->remote->credentials = ['alice', 'new-password'];

        expectApiError($this->actingAs($this->user, 'sanctum')->putJson("/api/v1/calendars/external/accounts/{$account->id}", ['password' => 'wrong']), 422);
        expect($account->fresh()->password)->toBe('app-password');

        $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/calendars/external/accounts/{$account->id}", ['password' => 'new-password'])
            ->assertStatus(200)
            ->assertJsonMissingPath('data.password');
        expect($account->fresh()->password)->toBe('new-password');
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
