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
