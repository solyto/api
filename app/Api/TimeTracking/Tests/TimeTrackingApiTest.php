<?php

use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\TimeTracking\Services\TimeTrackingService;

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

    it('stores and updates a project color', function () {
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/projects', ['title' => 'Colored', 'color' => '#112233'])
            ->assertStatus(201)
            ->assertJsonPath('data.color', '#112233');

        $projectId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/projects/'.$projectId, ['color' => '#445566'])
            ->assertStatus(200)
            ->assertJsonPath('data.color', '#445566');
    });

    it('rejects another user\'s categories on project store and update', function () {
        $user = makeUser();
        $other = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        $foreignCategory = TimeTrackingCategory::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/projects', ['title' => 'Solyto', 'category_ids' => [$foreignCategory->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_ids.0');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/projects/'.$project->id, ['category_ids' => [$foreignCategory->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_ids.0');

        expect(TimeTrackingProject::count())->toBe(1);
        expect($project->fresh()->categories)->toHaveCount(0);
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

    it('updates the has_exact_times flag on an entry', function () {
        $user = makeUser();
        $entry = TimeTrackingEntry::factory()->forUser($user)->create(['has_exact_times' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/entries/'.$entry->id, ['has_exact_times' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.has_exact_times', false);

        expect($entry->fresh()->has_exact_times)->toBeFalse();
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

    it('keeps project total_duration fresh across entry mutations', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertJsonPath('data.0.total_duration', 0);

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries', [
                'started_at' => now()->subHour()->toIso8601String(),
                'stopped_at' => now()->toIso8601String(),
                'duration_minutes' => 60,
                'project_id' => $project->id,
            ])
            ->assertStatus(201);

        $entryId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertJsonPath('data.0.total_duration', 60);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/entries/'.$entryId, ['duration_minutes' => 90])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertJsonPath('data.0.total_duration', 90);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/time-tracking/entries/'.$entryId)
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertJsonPath('data.0.total_duration', 0);
    });

    it('keeps project total_duration fresh after stopping a timer', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertJsonPath('data.0.total_duration', 0);

        $start = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/start', ['project_id' => $project->id])
            ->assertStatus(201);

        $entryId = $start->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/'.$entryId.'/stop')
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertJsonPath('data.0.total_duration', fn ($value) => $value >= 1);
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

    it('uses the project color in statistics', function () {
        $user = makeUser();
        $project = TimeTrackingProject::factory()->forUser($user)->withColor('#AABBCC')->create();

        TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create([
            'started_at' => now()->subDay(),
            'stopped_at' => now(),
            'duration_minutes' => 30,
        ]);

        $stats = app(TimeTrackingService::class)->getStatistics($user, now()->subWeek(), now());

        expect($stats['by_project'][0]['color'])->toBe('#AABBCC');
    });

    it('falls back to the first category color in statistics when the project has none', function () {
        $user = makeUser();
        $category = TimeTrackingCategory::factory()->forUser($user)->withColor('#DDEEFF')->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create(['color' => null]);
        $project->categories()->attach($category);

        TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create([
            'started_at' => now()->subDay(),
            'stopped_at' => now(),
            'duration_minutes' => 30,
        ]);

        $stats = app(TimeTrackingService::class)->getStatistics($user, now()->subWeek(), now());

        expect($stats['by_project'][0]['color'])->toBe('#DDEEFF');
    });

    it('filters statistics by category', function () {
        $user = makeUser();
        $category = TimeTrackingCategory::factory()->forUser($user)->create();
        $otherCategory = TimeTrackingCategory::factory()->forUser($user)->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        $otherProject = TimeTrackingProject::factory()->forUser($user)->create();
        $project->categories()->attach($category);
        $otherProject->categories()->attach($otherCategory);

        TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create([
            'started_at' => now()->subDay(),
            'stopped_at' => now(),
            'duration_minutes' => 30,
        ]);
        TimeTrackingEntry::factory()->forUser($user)->forProject($otherProject)->create([
            'started_at' => now()->subDay(),
            'stopped_at' => now(),
            'duration_minutes' => 45,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries/statistics?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString().'&category_id='.$category->id)
            ->assertStatus(200)
            ->assertJsonPath('data.total_minutes', 30)
            ->assertJsonCount(1, 'data.by_project');
    });

    it('filters statistics by several comma-separated categories', function () {
        $user = makeUser();
        $categories = [];

        foreach ([30, 45, 60] as $minutes) {
            $category = TimeTrackingCategory::factory()->forUser($user)->create();
            $project = TimeTrackingProject::factory()->forUser($user)->create();
            $project->categories()->attach($category);
            TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create([
                'started_at' => now()->subDay(),
                'stopped_at' => now(),
                'duration_minutes' => $minutes,
            ]);
            $categories[] = $category;
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries/statistics?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString().'&category_ids='.$categories[0]->id.','.$categories[1]->id)
            ->assertStatus(200)
            ->assertJsonPath('data.total_minutes', 75)
            ->assertJsonCount(2, 'data.by_project');
    });

    it('rejects malformed category_ids', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries/statistics?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString().'&category_ids=3,abc')
            ->assertStatus(422);
    });

    it('rejects an unknown category filter', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries/statistics?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString().'&category_id=999999')
            ->assertStatus(422);
    });

    it('returns empty statistics when no entries exist', function () {
        $user = makeUser();

        $stats = app(TimeTrackingService::class)->getStatistics($user, now()->subWeek(), now());

        expect($stats['total_minutes'])->toBe(0);
        expect($stats['by_project'])->toBe([]);
    });
});

describe('Time tracking tenant isolation', function () {
    it('hides another user\'s category and forbids writes to it', function () {
        $user = makeUser();
        $other = makeUser();
        $category = TimeTrackingCategory::factory()->forUser($other)->create(['title' => 'Theirs']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/categories')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/categories/'.$category->id, ['title' => 'Hijacked']), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/time-tracking/categories/'.$category->id), 403);

        expect($category->fresh()?->title)->toBe('Theirs');
    });

    it('hides another user\'s project and forbids access to it', function () {
        $user = makeUser();
        $other = makeUser();
        $project = TimeTrackingProject::factory()->forUser($other)->create(['title' => 'Theirs']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        expectApiError($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/projects/'.$project->id), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/projects/'.$project->id, ['title' => 'Hijacked']), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/time-tracking/projects/'.$project->id), 403);

        expect($project->fresh()?->title)->toBe('Theirs');
    });

    it('forbids tracking time on another user\'s project', function () {
        $user = makeUser();
        $other = makeUser();
        $project = TimeTrackingProject::factory()->forUser($other)->create();

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries', [
                'started_at' => now()->subHour()->toIso8601String(),
                'stopped_at' => now()->toIso8601String(),
                'duration_minutes' => 60,
                'project_id' => $project->id,
            ]), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/start', ['project_id' => $project->id]), 403);

        expect(TimeTrackingEntry::count())->toBe(0);
    });

    it('hides another user\'s entries and forbids writes to them', function () {
        $user = makeUser();
        $other = makeUser();
        $project = TimeTrackingProject::factory()->forUser($other)->create();
        $entry = TimeTrackingEntry::factory()->forUser($other)->forProject($project)->running()->create(['description' => 'Theirs']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/time-tracking/entries/'.$entry->id, ['description' => 'Hijacked']), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/time-tracking/entries/'.$entry->id.'/stop'), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/time-tracking/entries/'.$entry->id), 403);

        $entry = $entry->fresh();
        expect($entry)->not->toBeNull();
        expect($entry->description)->toBe('Theirs');
        expect($entry->stopped_at)->toBeNull();
    });

    it('leaves another user\'s entries out of the statistics', function () {
        $user = makeUser();
        $other = makeUser();
        $project = TimeTrackingProject::factory()->forUser($other)->create();
        TimeTrackingEntry::factory()->forUser($other)->forProject($project)->create([
            'started_at' => now()->subDay(),
            'stopped_at' => now(),
            'duration_minutes' => 30,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/time-tracking/entries/statistics?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString())
            ->assertStatus(200)
            ->assertJsonPath('data.total_minutes', 0)
            ->assertJsonCount(0, 'data.by_project');
    });
});
