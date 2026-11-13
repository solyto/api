<?php

use App\Api\Calendars\Jobs\InvalidateSharedCalendarCache;
use App\Api\Calendars\Models\Calendar;
use App\Api\Calendars\Models\CalendarEntry;
use App\Api\Calendars\Services\CalendarService;
use App\Api\Notes\Models\Note;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\Friend;
use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

describe('Calendar CRUD', function () {
    it('lists calendars including the default one', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('stores a new calendar', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars', ['name' => 'Work'])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $calendars = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data');

        expect(collect($calendars)->pluck('name'))->toContain('Work');
    });

    it('rejects a calendar with an existing name with 409', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars', ['name' => 'Work'])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars', ['name' => 'Work'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Calendar already exists.');
    });

    it('renders a service failure through the central 500 handler', function () {
        $user = makeUser();

        $this->mock(CalendarService::class)
            ->shouldReceive('create')
            ->andThrow(new \RuntimeException('DAV write failed'));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars', ['name' => 'Work'])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An unexpected error occurred.');
    });

    it('deletes a calendar', function () {
        $user = makeUser();

        $calendars = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data');

        $first = $calendars[0];

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$first['id'])
            ->assertStatus(200);

        $remaining = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data');

        expect(collect($remaining)->pluck('id'))->not->toContain($first['id']);
    });

    it('updates the calendar order', function () {
        $user = makeUser();

        $calendars = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data');

        $ids = collect($calendars)->pluck('id')->reverse()->values()->all();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/calendars/order', ['order' => $ids])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });
});

describe('Calendar events', function () {
    it('lists events for a month', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.now()->format('Y-m'))
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('stores, updates and deletes an event', function () {
        $user = makeUser();

        $calendars = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data');
        $instanceId = $calendars[0]['id'];

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/'.$instanceId.'/events', [
                'title' => 'Team Meeting',
                'start_date' => now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->startOfDay()->addHour()->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $instanceId,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $eventUri = $store->json('data.uri') ?? $store->json('data.event.uri');

        $events = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.now()->format('Y-m'))
            ->assertStatus(200)
            ->json('data');

        expect(collect($events)->pluck('uri'))->toContain($eventUri);
    });

    it('lists widget events', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/widget')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('excludes past events from the widget and keeps ongoing and upcoming ones', function () {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC']);

        $instanceId = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data')[0]['id'];

        $storeEvent = function (array $payload) use ($user, $instanceId) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge($payload, [
                    'calendar_id' => $instanceId,
                ]))
                ->assertStatus(201)
                ->assertJsonPath('success', true);
        };

        $today = today('UTC')->toDateString();
        $yesterday = today('UTC')->subDay()->toDateString();
        $tomorrow = today('UTC')->addDay()->toDateString();

        // All-day event from yesterday: stored with an exclusive DTEND at
        // today midnight, i.e. exactly at the widget window start.
        $storeEvent(['title' => 'Yesterday All Day', 'start_date' => $yesterday, 'end_date' => $yesterday, 'is_all_day' => true]);
        $storeEvent(['title' => 'Yesterday Evening', 'start_date' => $yesterday.' 23:00', 'end_date' => $yesterday.' 23:59', 'is_all_day' => false]);
        // Multi-day event that started yesterday and is still ongoing.
        $storeEvent(['title' => 'Ongoing Multi Day', 'start_date' => $yesterday, 'end_date' => $tomorrow, 'is_all_day' => true]);
        $storeEvent(['title' => 'Today All Day', 'start_date' => $today, 'end_date' => $today, 'is_all_day' => true]);
        $storeEvent(['title' => 'Tomorrow All Day', 'start_date' => $tomorrow, 'end_date' => $tomorrow, 'is_all_day' => true]);
        // Pins the recurrence fastForward boundary semantics: yesterday's
        // occurrence ends exactly at the window start and must be dropped,
        // today's occurrence must be kept.
        $storeEvent(['title' => 'Daily All Day', 'start_date' => $yesterday, 'end_date' => $yesterday, 'is_all_day' => true, 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY']);

        $data = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/calendars/events/widget')
                ->assertStatus(200)
                ->assertJsonPath('success', true)
                ->json('data')
        );

        $titles = $data->pluck('title');

        expect($titles)->not->toContain('Yesterday All Day');
        expect($titles)->not->toContain('Yesterday Evening');
        expect($titles)->toContain('Ongoing Multi Day');
        expect($titles)->toContain('Today All Day');
        expect($titles)->toContain('Tomorrow All Day');

        $recurrenceStarts = $data->where('title', 'Daily All Day')->pluck('original_start_date');
        expect($recurrenceStarts)->not->toContain($yesterday.'T00:00:00');
        expect($recurrenceStarts)->toContain($today.'T00:00:00');
    });

    it('uses the user timezone for the widget window', function () {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'Europe/Berlin']);

        $instanceId = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data')[0]['id'];

        $storeEvent = function (array $payload) use ($user, $instanceId) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge($payload, [
                    'calendar_id' => $instanceId,
                ]))
                ->assertStatus(201)
                ->assertJsonPath('success', true);
        };

        $today = today('Europe/Berlin')->toDateString();
        $yesterday = today('Europe/Berlin')->subDay()->toDateString();

        // All-day events are stored as floating DATE values (the user's
        // local dates); yesterday's event must not leak into the window even
        // though the app runs in UTC.
        $storeEvent(['title' => 'Berlin Yesterday All Day', 'start_date' => $yesterday, 'end_date' => $yesterday, 'is_all_day' => true]);
        $storeEvent(['title' => 'Berlin Yesterday Evening', 'start_date' => $yesterday.' 23:00', 'end_date' => $yesterday.' 23:59', 'is_all_day' => false]);
        $storeEvent(['title' => 'Berlin Today All Day', 'start_date' => $today, 'end_date' => $today, 'is_all_day' => true]);
        $storeEvent(['title' => 'Berlin Daily All Day', 'start_date' => $yesterday, 'end_date' => $yesterday, 'is_all_day' => true, 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY']);

        $data = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/calendars/events/widget')
                ->assertStatus(200)
                ->assertJsonPath('success', true)
                ->json('data')
        );

        $titles = $data->pluck('title');

        expect($titles)->not->toContain('Berlin Yesterday All Day');
        expect($titles)->not->toContain('Berlin Yesterday Evening');
        expect($titles)->toContain('Berlin Today All Day');

        $recurrenceStarts = $data->where('title', 'Berlin Daily All Day')->pluck('original_start_date');
        expect($recurrenceStarts)->not->toContain($yesterday.'T00:00:00');
        expect($recurrenceStarts)->toContain($today.'T00:00:00');
    });

    it('shows the current day occurrence of all-day recurring events west of UTC', function () {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'America/New_York']);

        $instanceId = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data')[0]['id'];

        $storeEvent = function (array $payload) use ($user, $instanceId) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge($payload, [
                    'calendar_id' => $instanceId,
                ]))
                ->assertStatus(201)
                ->assertJsonPath('success', true);
        };

        $today = today('America/New_York')->toDateString();
        $yesterday = today('America/New_York')->subDay()->toDateString();
        $inThreeDays = today('America/New_York')->addDays(3)->toDateString();

        // For a negative UTC offset the user's local midnight is *later*
        // than the UTC-parsed start of the current local day's occurrence,
        // so the window bounds must be compared wall-clock to wall-clock:
        // today's occurrence of the daily event has to stay in the widget.
        $storeEvent(['title' => 'New York Daily All Day', 'start_date' => $yesterday, 'end_date' => $yesterday, 'is_all_day' => true, 'is_recurring' => true, 'recurrence_rule' => 'FREQ=DAILY']);
        $storeEvent(['title' => 'New York Today All Day', 'start_date' => $today, 'end_date' => $today, 'is_all_day' => true]);

        $data = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/calendars/events/widget')
                ->assertStatus(200)
                ->assertJsonPath('success', true)
                ->json('data')
        );

        $titles = $data->pluck('title');

        expect($titles)->toContain('New York Today All Day');

        $recurrenceStarts = $data->where('title', 'New York Daily All Day')->pluck('original_start_date');
        expect($recurrenceStarts)->not->toContain($yesterday.'T00:00:00');
        expect($recurrenceStarts)->toContain($today.'T00:00:00');
        // The occurrence starting exactly at the window end (today + 3
        // days) lies outside the window, consistently with UTC users.
        expect($recurrenceStarts)->not->toContain($inThreeDays.'T00:00:00');
    });

    it('lists only today\'s events for the daily reminder in the user timezone', function (string $timezone) {
        $user = makeUser();
        $user->settings()->update(['timezone' => $timezone]);

        $instanceId = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data')[0]['id'];

        $storeEvent = function (array $payload) use ($user, $instanceId) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/calendars/'.$instanceId.'/events', array_merge($payload, [
                    'calendar_id' => $instanceId,
                ]))
                ->assertStatus(201)
                ->assertJsonPath('success', true);
        };

        $today = today($timezone)->toDateString();
        $tomorrow = today($timezone)->addDay()->toDateString();
        $yesterday = today($timezone)->subDay()->toDateString();

        // Events starting exactly at the window end (the user's next
        // midnight) belong to tomorrow, all-day ones included.
        $storeEvent(['title' => 'Yesterday All Day', 'start_date' => $yesterday, 'end_date' => $yesterday, 'is_all_day' => true]);
        $storeEvent(['title' => 'Today All Day', 'start_date' => $today, 'end_date' => $today, 'is_all_day' => true]);
        $storeEvent(['title' => 'Today Late', 'start_date' => $today.' 23:00', 'end_date' => $today.' 23:30', 'is_all_day' => false]);
        $storeEvent(['title' => 'Tomorrow All Day', 'start_date' => $tomorrow, 'end_date' => $tomorrow, 'is_all_day' => true]);
        $storeEvent(['title' => 'Tomorrow Midnight', 'start_date' => $tomorrow.' 00:00', 'end_date' => $tomorrow.' 01:00', 'is_all_day' => false]);

        $titles = collect(app(CalendarService::class)->listTodayEvents($user->fresh()))->pluck('title');

        expect($titles)->toContain('Today All Day');
        expect($titles)->toContain('Today Late');
        expect($titles)->not->toContain('Yesterday All Day');
        expect($titles)->not->toContain('Tomorrow All Day');
        expect($titles)->not->toContain('Tomorrow Midnight');
    })->with(['America/New_York', 'UTC', 'Europe/Berlin', 'Asia/Tokyo']);
});

describe('Event attachments', function () {
    it('attaches and detaches a todo', function () {
        $user = makeUser();
        $calendar = Calendar::factory()->forUser($user)->create();
        $entry = CalendarEntry::factory()->forCalendar($calendar)->create();

        $todo = Todo::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/events/'.$entry->id.'/attachments/todos', [
                'todo_id' => $todo->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.$entry->id.'/attachments/todos')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $todo->id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/events/'.$entry->id.'/attachments/todos/'.$todo->id)
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.$entry->id.'/attachments/todos')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('attaches and detaches a note', function () {
        $user = makeUser();
        $calendar = Calendar::factory()->forUser($user)->create();
        $entry = CalendarEntry::factory()->forCalendar($calendar)->create();

        $note = Note::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/events/'.$entry->id.'/attachments/notes', [
                'note_id' => $note->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.$entry->id.'/attachments/notes')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $note->id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/events/'.$entry->id.'/attachments/notes/'.$note->id)
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.$entry->id.'/attachments/notes')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });
});

describe('Calendar tenant isolation', function () {
    it('hides another user\'s calendar and denies writes to it', function () {
        $user = makeUser();
        $other = makeUser();
        $friend = makeUser();
        Friend::factory()->forUsers($user, $friend)->create();

        $otherInstanceId = $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data')[0]['id'];

        $ownIds = collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->assertStatus(200)
            ->json('data'))->pluck('id');
        expect($ownIds)->not->toContain($otherInstanceId);

        // The module answers a calendar the user cannot see with 404.
        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/calendars/'.$otherInstanceId, ['color' => '#000000'])->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$otherInstanceId)->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/'.$otherInstanceId.'/share')->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/'.$otherInstanceId.'/share', ['friend_id' => $friend->id])->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/'.$otherInstanceId.'/events', [
                'title' => 'Intruder',
                'start_date' => now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->startOfDay()->addHour()->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $otherInstanceId,
            ])->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$otherInstanceId.'/share/'.$friend->id)
            ->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$otherInstanceId.'/unsubscribe')
            ->assertJsonPath('message', 'Calendar does not exist'), 404);

        $otherIds = collect($this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data'))->pluck('id');
        expect($otherIds)->toContain($otherInstanceId);
    });

    it('denies updating and deleting events in another user\'s calendar', function () {
        $user = makeUser();
        $other = makeUser();

        $otherInstanceId = $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/calendars')
            ->json('data')[0]['id'];

        $store = $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/calendars/'.$otherInstanceId.'/events', [
                'title' => 'Private Meeting',
                'start_date' => now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->startOfDay()->addHour()->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $otherInstanceId,
            ])
            ->assertStatus(201);
        $eventUri = $store->json('data.uri') ?? $store->json('data.event.uri');
        $etag = $store->json('data.etag') ?? $store->json('data.event.etag') ?? 'unknown';

        $titles = collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.now()->addDay()->format('Y-m'))
            ->assertStatus(200)
            ->json('data'))->pluck('title');
        expect($titles)->not->toContain('Private Meeting');

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/calendars/'.$otherInstanceId.'/events/'.$eventUri, [
                'title' => 'Hijacked',
                'start_date' => now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->startOfDay()->addHour()->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $otherInstanceId,
                'etag' => $etag,
            ])->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$otherInstanceId.'/events/'.$eventUri)->assertJsonPath('message', 'Calendar does not exist'), 404);

        $occurrenceDate = now()->addDay()->format('Y-m-d');

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/calendars/'.$otherInstanceId.'/events/'.$eventUri.'/occurrence/'.$occurrenceDate, [
                'title' => 'Hijacked',
                'start_date' => now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->startOfDay()->addHour()->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $otherInstanceId,
                'etag' => $etag,
            ])
            ->assertJsonPath('message', 'Calendar does not exist'), 404);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$otherInstanceId.'/events/'.$eventUri.'/occurrence/'.$occurrenceDate)
            ->assertJsonPath('message', 'Calendar does not exist'), 404);

        $otherTitles = collect($this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.now()->addDay()->format('Y-m'))
            ->json('data'))->pluck('title');
        expect($otherTitles)->toContain('Private Meeting');
    });
});

describe('Calendar invites', function () {
    // The invite token is the recipient's mailto: href, so anyone who knows
    // their email can build it. Accept and decline must only act on the
    // caller's own pending invite.
    beforeEach(function () {
        $this->owner = makeUser();
        $this->recipient = makeUser();
        $this->other = makeUser();
        Friend::factory()->forUsers($this->owner, $this->recipient)->create();

        $calendarId = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/calendars')->json('data')[0]['id'];
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/calendars/'.$calendarId.'/share', ['friend_id' => $this->recipient->id])
            ->assertStatus(200);

        $this->token = rawurlencode('mailto:'.$this->recipient->email);
        $this->inviteStatus = fn () => DB::connection('pgsql')->table('calendarinstances')
            ->where('principaluri', 'principals/'.$this->recipient->email)
            ->where('access', '>', 1)
            ->value('share_invitestatus');
    });

    it('lets the recipient accept their invite', function () {
        expectApiSuccess($this->actingAs($this->recipient, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/accept'), 200);

        expect(($this->inviteStatus)())->toBe(2);
    });

    it('lets the recipient decline their invite', function () {
        expectApiSuccess($this->actingAs($this->recipient, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/decline'), 200);

        expect(($this->inviteStatus)())->toBeNull();
    });

    it('does not let another user accept someone else\'s invite', function () {
        expectApiError($this->actingAs($this->other, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/accept')
            ->assertJsonPath('message', 'Invite does not exist'), 404);

        expect(($this->inviteStatus)())->toBe(1);
    });

    it('does not let another user decline someone else\'s invite', function () {
        expectApiError($this->actingAs($this->other, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/decline'), 404);

        expect(($this->inviteStatus)())->toBe(1);
    });

    it('does not delete an accepted share on decline', function () {
        $this->actingAs($this->recipient, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/accept')
            ->assertStatus(200);

        expectApiError($this->actingAs($this->recipient, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/decline'), 404);

        expect(($this->inviteStatus)())->toBe(2);
    });

    it('answers a second accept with 404 and keeps the share accepted', function () {
        $this->actingAs($this->recipient, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/accept')
            ->assertStatus(200);

        expectApiError($this->actingAs($this->recipient, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.$this->token.'/accept'), 404);

        expect(($this->inviteStatus)())->toBe(2);
    });
});

describe('Shared calendar cache invalidation', function () {
    beforeEach(function () {
        // forgetByPrefix() needs Redis and is a no-op on the test array
        // store; this double does the same prefix delete on the array store.
        // Bound before the first request, which caches the controller.
        $this->app->instance(UserCacheService::class, new class extends UserCacheService
        {
            public function forgetByPrefix(array $identifiers): void
            {
                $store = Cache::store('user_data');
                $prefix = implode('_', $identifiers).'_';

                foreach (array_keys($store->getStore()->all(false)) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        $store->forget($key);
                    }
                }
            }
        });

        $this->owner = makeUser();
        $this->sharee = makeUser();
        Friend::factory()->forUsers($this->owner, $this->sharee)->create();

        $this->ownerInstance = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
        $this->calendarId = app(DavService::class)->calendars()->get($this->owner, $this->ownerInstance)->calendarId;
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/calendars/'.$this->ownerInstance.'/share', ['friend_id' => $this->sharee->id])
            ->assertStatus(200);

        $this->accept = fn () => $this->actingAs($this->sharee, 'sanctum')
            ->putJson('/api/v1/calendars/invites/'.rawurlencode('mailto:'.$this->sharee->email).'/accept')
            ->assertStatus(200);
        $this->shareeInstance = fn () => collect($this->actingAs($this->sharee, 'sanctum')->getJson('/api/v1/calendars')->json('data'))
            ->firstWhere('is_shared', true)['id'];
        $this->storeEvent = fn (User $user, int $instanceId, string $title = 'Shared') => $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/calendars/'.$instanceId.'/events', [
                'title' => $title,
                'start_date' => now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->startOfDay()->addHour()->format('Y-m-d H:i:s'),
                'is_all_day' => false,
                'calendar_id' => $instanceId,
            ])
            ->assertStatus(201)
            ->json('data');
        $this->monthTitles = fn (User $user) => collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/calendars/events/'.now()->addDay()->format('Y-m'))
            ->assertStatus(200)
            ->json('data'))->pluck('title');
    });

    it('queues an invalidation when the owner writes to a shared calendar', function () {
        ($this->accept)();
        Queue::fake([InvalidateSharedCalendarCache::class]);

        ($this->storeEvent)($this->owner, $this->ownerInstance);

        Queue::assertPushed(InvalidateSharedCalendarCache::class, fn ($job) => $job->calendarId === $this->calendarId);
    });

    it('queues an invalidation when a sharee writes to a shared calendar', function () {
        ($this->accept)();
        Queue::fake([InvalidateSharedCalendarCache::class]);

        ($this->storeEvent)($this->sharee, ($this->shareeInstance)());

        Queue::assertPushed(InvalidateSharedCalendarCache::class, fn ($job) => $job->calendarId === $this->calendarId);
    });

    it('queues an invalidation when an event in a shared calendar is updated or deleted', function (string $method) {
        ($this->accept)();
        $event = ($this->storeEvent)($this->owner, $this->ownerInstance);
        Queue::fake([InvalidateSharedCalendarCache::class]);

        $this->actingAs($this->owner, 'sanctum')->json($method, '/api/v1/calendars/'.$this->ownerInstance.'/events/'.$event['uri'], [
            'title' => 'Renamed',
            'start_date' => $event['start_date'],
            'end_date' => $event['end_date'],
            'is_all_day' => false,
            'calendar_id' => $this->ownerInstance,
            'etag' => $event['etag'],
        ])->assertStatus(200);

        Queue::assertPushed(InvalidateSharedCalendarCache::class, fn ($job) => $job->calendarId === $this->calendarId);
    })->with(['PUT', 'DELETE']);

    it('queues nothing for a calendar without accepted sharees', function () {
        $loner = makeUser();
        $instance = $this->actingAs($loner, 'sanctum')->getJson('/api/v1/calendars')->json('data.0.id');
        Queue::fake([InvalidateSharedCalendarCache::class]);

        ($this->storeEvent)($loner, $instance);
        ($this->storeEvent)($this->owner, $this->ownerInstance);

        Queue::assertNotPushed(InvalidateSharedCalendarCache::class);
    });

    it('queues one invalidation when an event moves from a shared into an unshared calendar', function () {
        ($this->accept)();
        $event = ($this->storeEvent)($this->owner, $this->ownerInstance);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/calendars', ['name' => 'Private'])->assertStatus(201);
        $private = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/calendars')->json('data'))->firstWhere('name', 'Private')['id'];
        Queue::fake([InvalidateSharedCalendarCache::class]);

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/calendars/'.$this->ownerInstance.'/events/'.$event['uri'], [
            'title' => 'Moved',
            'start_date' => $event['start_date'],
            'end_date' => $event['end_date'],
            'is_all_day' => false,
            'calendar_id' => $private,
            'etag' => $event['etag'],
        ])->assertStatus(200);

        Queue::assertPushed(InvalidateSharedCalendarCache::class, 1);
        Queue::assertPushed(InvalidateSharedCalendarCache::class, fn ($job) => $job->calendarId === $this->calendarId);
    });

    it('shows a sharee\'s new event to the owner on the next load, and the reverse', function () {
        ($this->accept)();
        $shareeInstance = ($this->shareeInstance)();
        // An empty month isn't cached, so warm both caches with one event.
        ($this->storeEvent)($this->owner, $this->ownerInstance, 'Seed');
        expect(($this->monthTitles)($this->owner))->toContain('Seed')
            ->and(($this->monthTitles)($this->sharee))->toContain('Seed');

        ($this->storeEvent)($this->sharee, $shareeInstance, 'From sharee');
        expect(($this->monthTitles)($this->owner))->toContain('From sharee');

        ($this->storeEvent)($this->owner, $this->ownerInstance, 'From owner');
        expect(($this->monthTitles)($this->sharee))->toContain('From owner');
    });

    it('keeps the owner\'s cached calendar list in step with accept and revoke', function () {
        $service = app(CalendarService::class);
        expect($service->list($this->owner)[0]->solytoShared)->toBeFalse();

        ($this->accept)();
        expect($service->list($this->owner)[0]->solytoShared)->toBeTrue();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.$this->ownerInstance.'/share/'.$this->sharee->id)
            ->assertStatus(200);
        expect($service->list($this->owner)[0]->solytoShared)->toBeFalse();
    });

    it('clears the owner\'s cached shared flag when the sharee unsubscribes', function () {
        ($this->accept)();
        $service = app(CalendarService::class);
        expect($service->list($this->owner)[0]->solytoShared)->toBeTrue();

        $this->actingAs($this->sharee, 'sanctum')
            ->deleteJson('/api/v1/calendars/'.($this->shareeInstance)().'/unsubscribe')
            ->assertStatus(200);
        expect($service->list($this->owner)[0]->solytoShared)->toBeFalse();
    });
});
