<?php

use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TimeTrackingEntry Factory', function () {
    it('creates a valid time tracking entry', function () {
        $user = User::factory()->create();
        $entry = TimeTrackingEntry::factory()->forUser($user)->create();

        expect($entry->description)->toBeString();
        expect($entry->started_at)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($entry->stopped_at)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($entry->duration_minutes)->toBeInt();
        expect($entry->user_id)->toBe($user->id);
    });

    it('creates a running entry', function () {
        $user = User::factory()->create();
        $entry = TimeTrackingEntry::factory()->forUser($user)->running()->create([
            'stopped_at' => null,
        ]);

        expect($entry->stopped_at)->toBeNull();
        expect($entry->description)->not()->toBeEmpty();
    });

    it('creates a completed entry', function () {
        $user = User::factory()->create();
        $entry = TimeTrackingEntry::factory()->forUser($user)->create([
            'started_at' => now()->subHours(2),
            'stopped_at' => now()->subHour(),
            'duration_minutes' => 60,
        ]);

        expect($entry->duration_minutes)->toBe(60);
    });

    it('creates a short entry', function () {
        $entry = TimeTrackingEntry::factory()->forUser($user)->short()->create();

        expect($entry->duration_minutes)->toBeLessThan(60);
    });

    it('creates a long entry', function () {
        $entry = TimeTrackingEntry::factory()->forUser($user)->long()->create();

        expect($entry->duration_minutes)->toBeGreaterThan(60);
    });

    it('creates an entry with custom duration', function () {
        $entry = TimeTrackingEntry::factory()->forUser($user)->withDuration(120)->create();

        expect($entry->duration_minutes)->toBe(120);
    });

    it('creates an entry for user', function () {
        $user = User::factory()->create();
        $entry = TimeTrackingEntry::factory()->forUser($user)->create();

        expect($entry->user_id)->toBe($user->id);
    });

    it('can belong to a project', function () {
        $user = User::factory()->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        $entry = TimeTrackingEntry::factory()->forUser($user)->forProject($project)->create();

        expect($entry->project_id)->toBe($project->id);
    });
});

describe('TimeTrackingProject Factory', function () {
    it('creates a valid project', function () {
        $user = User::factory()->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        expect($project->title)->toBeString();
        expect($project->user_id)->toBe($user->id);
    });

    it('creates a project with description', function () {
        $user = User::factory()->create();
        $project = TimeTrackingProject::factory()->withDescription('My project description')->create();

        expect($project->description)->toBe('My project description');
    });

    it('creates a project for user', function () {
        $user = User::factory()->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create();

        expect($project->user_id)->toBe($user->id);
    });

    it('can have categories', function () {
        $user = User::factory()->create();
        $category = TimeTrackingCategory::factory()->forUser($user)->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        $project->categories()->attach($category);

        expect($project->categories)->toHaveCount(1);
    });

    it('can have entries', function () {
        $user = User::factory()->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        TimeTrackingEntry::factory()->forUser($user)->create(3);

        expect($project->entries)->toHaveCount(3);
    });
});

describe('TimeTrackingCategory Factory', function () {
    it('creates a valid category', function () {
        $user = User::factory()->create();
        $category = TimeTrackingCategory::factory()->forUser($user)->create();

        expect($category->title)->toBeString();
        expect($category->color)->toBeString();
        expect($category->user_id)->toBe($user->id);
    });

    it('creates a category with custom title', function () {
        $category = TimeTrackingCategory::factory()->withTitle('Work')->create();

        expect($category->title)->toBe('Work');
    });

    it('creates a category with custom color', function () {
        $category = TimeTrackingCategory::factory()->withColor('#FF0000')->create();

        expect($category->color)->toBe('#FF0000');
    });

    it('creates a category for user', function () {
        $user = User::factory()->create();
        $category = TimeTrackingCategory::factory()->forUser($user)->create();

        expect($category->user_id)->toBe($user->id);
    });

    it('can have projects', function () {
        $user = User::factory()->create();
        $project = TimeTrackingProject::factory()->forUser($user)->create();
        $category = TimeTrackingCategory::factory()->forUser($user)->create();
        $project->categories()->attach($category);

        expect($project->categories)->toHaveCount(1);
    });
});
