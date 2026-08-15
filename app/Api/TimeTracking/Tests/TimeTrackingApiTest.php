<?php

use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\TimeTracking\Services\TimeTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Time tracking categories and projects', function () {
    it('stores, lists and deletes categories', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/categories', ['title' => 'Work', 'color' => '#FF0000'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Work');

        $categoryId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/time-tracking/categories/'.$categoryId)
            ->assertStatus(200);

        expect(TimeTrackingCategory::count())->toBe(0);
    });

    it('stores, lists, updates and deletes projects', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/projects', ['title' => 'Solyto', 'description' => 'Main project'])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Solyto');

        $projectId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/projects/'.$projectId, ['title' => 'Solyto v2'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Solyto v2');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/time-tracking/projects/'.$projectId)
            ->assertStatus(200);

        expect(TimeTrackingProject::count())->toBe(0);
    });
});

describe('Time tracking entries', function () {
    it('stores, lists and deletes entries', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries', [
                'description' => 'Worked on feature',
                'started_at' => now()->subHour()->toIso8601String(),
                'stopped_at' => now()->toIso8601String(),
                'duration_minutes' => 60,
                'project_id' => $project->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.description', 'Worked on feature');

        $entryId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/time-tracking/entries/'.$entryId)
            ->assertStatus(200);

        expect(TimeTrackingEntry::count())->toBe(0);
    });

    it('starts a timer and prevents a second running timer', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/start', [
                'project_id' => $project->id,
                'description' => 'Focus',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.description', 'Focus');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/start', [
                'project_id' => $project->id,
            ])
            ->assertStatus(409);
    });

    it('stops a running timer and records the duration', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        $start = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/start', [
                'project_id' => $project->id,
            ])
            ->assertStatus(201);

        $entryId = $start->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/'.$entryId.'/stop')
            ->assertStatus(200)
            ->assertJsonPath('data.duration_minutes', 1)
            ->assertJsonPath('data.stopped_at', fn ($value) => $value !== null);
    });

    it('refuses to stop an already stopped timer', function () {
        $user = makeUser();
        $entry = TimeTrackingEntry::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/'.$entry->id.'/stop')
            ->assertStatus(409);
    });
});

describe('TimeTrackingService statistics', function () {
    it('aggregates minutes by project in a date range', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        TimeTrackingEntry::factory()->forUser($user)->forProject($project)->count(2)->create([
            'started_at' => now()->subDay(),
            'stopped_at' => now(),
            'duration_minutes' => 30,
        ]);
        TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create([
            'started_at' => now()->subMonths(3),
            'stopped_at' => now()->subMonths(3)->addHour(),
            'duration_minutes' => 60,
        ]);

        $stats = app(TimeTrackingService::class)->getStatistics($user, now()->subWeek(), now());

        expect($stats['total_minutes'])->toBe(60);
        expect($stats['by_project'])->toHaveCount(1);
        expect($stats['by_project'][0]['project_id'])->toBe($project->id);
        expect($stats['by_project'][0]['project_title'])->toBe($project->title);
    });

    it('returns empty statistics when no entries exist', function () {
        $user = makeUser();

        $stats = app(TimeTrackingService::class)->getStatistics($user, now()->subWeek(), now());

        expect($stats['total_minutes'])->toBe(0);
        expect($stats['by_project'])->toBe([]);
    });
});
