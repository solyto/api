<?php

use App\Api\Planner\Models\PlannerEntry;
use App\Api\Planner\Services\PlannerEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('GET /api/v1/planner/entries', function () {
    it('lists entries within the range ordered by date, start_minutes and sort_order', function () {
        $user = makeUser();

        PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-20', 'start_minutes' => 60]);
        $late = PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-12', 'start_minutes' => 600, 'sort_order' => 0]);
        $early = PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-12', 'start_minutes' => 540, 'sort_order' => 0]);
        $nextDay = PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-13', 'start_minutes' => 0, 'sort_order' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries?from=2026-10-12&to=2026-10-18')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        expect($response->json('data.0.id'))->toBe($early->id);
        expect($response->json('data.1.id'))->toBe($late->id);
        expect($response->json('data.2.id'))->toBe($nextDay->id);
    });

    it('breaks identical start_minutes ties with sort_order', function () {
        $user = makeUser();
        $second = PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-12', 'start_minutes' => 540, 'sort_order' => 2]);
        $first = PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-12', 'start_minutes' => 540, 'sort_order' => 1]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries?from=2026-10-12&to=2026-10-12')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        expect($response->json('data.0.id'))->toBe($first->id);
        expect($response->json('data.1.id'))->toBe($second->id);
    });

    it('scopes entries to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        PlannerEntry::factory()->forUser($other)->create(['date' => '2026-10-12']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries?from=2026-10-12&to=2026-10-18')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('returns an empty array for an empty range', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries?from=2026-10-12&to=2026-10-18')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    });

    it('rejects a missing, malformed or reversed range', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries')
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries?from=2026-10-01&to=2026-09-01')
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries?from=01.10.2026&to=31.10.2026')
            ->assertStatus(422);
    });

    it('rejects a range longer than 366 days', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/planner/entries?from=2025-01-01&to=2026-10-01')
            ->assertStatus(422);
    });
});

describe('POST /api/v1/planner/entries', function () {
    it('creates a planner entry with defaults', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', [
                'date' => '2026-10-12',
                'title' => 'Gym',
                'start_minutes' => 540,
                'duration_minutes' => 60,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Gym')
            ->assertJsonPath('data.start_minutes', 540)
            ->assertJsonPath('data.is_all_day', false)
            ->assertJsonPath('data.color', null)
            ->assertJsonPath('data.todo_id', null)
            ->assertJsonPath('data.sort_order', 0);

        expect(PlannerEntry::where('user_id', $user->id)->count())->toBe(1);
    });

    it('accepts all optional fields', function () {
        $user = makeUser();
        $todoId = (string) Str::uuid();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', [
                'date' => '2026-10-12',
                'title' => 'Focus block',
                'start_minutes' => 0,
                'duration_minutes' => 1440,
                'is_all_day' => true,
                'color' => '#FF5733',
                'todo_id' => $todoId,
                'sort_order' => 3,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.is_all_day', true)
            ->assertJsonPath('data.color', '#FF5733')
            ->assertJsonPath('data.todo_id', $todoId)
            ->assertJsonPath('data.sort_order', 3);
    });

    it('requires date, title, start and duration', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date', 'title', 'start_minutes', 'duration_minutes']);
    });

    it('rejects out-of-bounds start and duration', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', ['date' => '2026-10-12', 'title' => 'x', 'start_minutes' => 1440, 'duration_minutes' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('start_minutes');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', ['date' => '2026-10-12', 'title' => 'x', 'start_minutes' => 600, 'duration_minutes' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', ['date' => '2026-10-12', 'title' => 'x', 'start_minutes' => 1400, 'duration_minutes' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
    });

    it('rejects a bad date and an invalid color', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', ['date' => '12-10-2026', 'title' => 'x', 'start_minutes' => 0, 'duration_minutes' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('date');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/planner/entries', ['date' => '2026-10-12', 'title' => 'x', 'start_minutes' => 0, 'duration_minutes' => 30, 'color' => 'red'])
            ->assertStatus(422)->assertJsonValidationErrors('color');
    });
});

describe('PATCH /api/v1/planner/entries/{id}', function () {
    it('partially updates a planner entry', function () {
        $user = makeUser();
        $entry = PlannerEntry::factory()->forUser($user)->create([
            'title' => 'Old',
            'start_minutes' => 100,
            'duration_minutes' => 30,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/planner/entries/'.$entry->id, ['start_minutes' => 200])
            ->assertStatus(200)
            ->assertJsonPath('data.start_minutes', 200)
            ->assertJsonPath('data.title', 'Old')
            ->assertJsonPath('data.duration_minutes', 30);
    });

    it('clears the todo reference when explicitly set to null', function () {
        $user = makeUser();
        $entry = PlannerEntry::factory()->forUser($user)->create([
            'todo_id' => (string) Str::uuid(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/planner/entries/'.$entry->id, ['todo_id' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.todo_id', null);
    });

    it('applies the same validation to updated fields', function () {
        $user = makeUser();
        $entry = PlannerEntry::factory()->forUser($user)->create([
            'start_minutes' => 600,
            'duration_minutes' => 60,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/planner/entries/'.$entry->id, ['duration_minutes' => 1200])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
    });

    it('forbids updating another users entry', function () {
        $user = makeUser();
        $other = makeUser();
        $entry = PlannerEntry::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/planner/entries/'.$entry->id, ['title' => 'Nope'])
            ->assertStatus(403);
    });

    it('returns 404 for a missing entry', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/planner/entries/999999', ['title' => 'Nope'])
            ->assertStatus(404);
    });
});

describe('DELETE /api/v1/planner/entries/{id}', function () {
    it('deletes a planner entry', function () {
        $user = makeUser();
        $entry = PlannerEntry::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/planner/entries/'.$entry->id)
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(PlannerEntry::find($entry->id))->toBeNull();
    });

    it('forbids deleting another users entry', function () {
        $user = makeUser();
        $other = makeUser();
        $entry = PlannerEntry::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/planner/entries/'.$entry->id)
            ->assertStatus(403);

        expect(PlannerEntry::find($entry->id))->not->toBeNull();
    });

    it('returns 404 for a missing entry', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/planner/entries/999999')
            ->assertStatus(404);
    });
});

describe('PlannerEntryService cache', function () {
    it('serves repeated range reads from cache until a service write invalidates it', function () {
        $user = makeUser();
        $service = app(PlannerEntryService::class);

        PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-12']);
        expect($service->list($user, '2026-10-12', '2026-10-18'))->toHaveCount(1);

        PlannerEntry::factory()->forUser($user)->create(['date' => '2026-10-13']);
        expect($service->list($user, '2026-10-12', '2026-10-18'))->toHaveCount(1);

        $service->create($user, [
            'date' => '2026-10-14',
            'title' => 'New block',
            'start_minutes' => 0,
            'duration_minutes' => 30,
        ]);

        expect($service->list($user, '2026-10-12', '2026-10-18'))->toHaveCount(3);
    });
});
