<?php

use App\Api\Calendars\Models\Calendar;
use App\Api\Calendars\Models\CalendarEntry;
use App\Api\Notes\Models\Note;
use App\Api\Todos\Models\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
