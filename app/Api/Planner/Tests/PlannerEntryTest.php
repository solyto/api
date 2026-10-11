<?php

use App\Api\Planner\Models\PlannerEntry;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('PlannerEntry Factory', function () {
    it('creates a valid planner entry', function () {
        $user = User::factory()->create();
        $entry = PlannerEntry::factory()->forUser($user)->create();

        expect($entry->title)->toBeString();
        expect($entry->user_id)->toBe($user->id);
        expect($entry->start_minutes)->toBeInt();
        expect($entry->duration_minutes)->toBeInt();
        expect($entry->is_all_day)->toBeFalse();
    });

    it('creates an all-day entry', function () {
        $entry = PlannerEntry::factory()->allDay()->create();

        expect($entry->is_all_day)->toBeTrue();
        expect($entry->start_minutes)->toBe(0);
        expect($entry->duration_minutes)->toBe(1440);
    });

    it('creates an entry for a specific date', function () {
        $entry = PlannerEntry::factory()->forDate('2026-10-12')->create();

        expect($entry->date->toDateString())->toBe('2026-10-12');
    });
});

describe('PlannerEntry Model', function () {
    it('has correct fillable attributes', function () {
        $entry = new PlannerEntry;

        expect($entry->getFillable())->toContain('user_id');
        expect($entry->getFillable())->toContain('date');
        expect($entry->getFillable())->toContain('title');
        expect($entry->getFillable())->toContain('start_minutes');
        expect($entry->getFillable())->toContain('duration_minutes');
        expect($entry->getFillable())->toContain('is_all_day');
        expect($entry->getFillable())->toContain('color');
        expect($entry->getFillable())->toContain('todo_id');
        expect($entry->getFillable())->toContain('sort_order');
    });

    it('belongs to a user', function () {
        $user = User::factory()->create();
        $entry = PlannerEntry::factory()->forUser($user)->create();

        expect($entry->user->id)->toBe($user->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        PlannerEntry::factory()->forUser($user1)->create();
        PlannerEntry::factory()->forUser($user2)->create();

        expect(PlannerEntry::forUser($user1->id)->get())->toHaveCount(1);
    });

    it('cascades planner entries when the user is deleted', function () {
        $user = User::factory()->create();
        PlannerEntry::factory()->forUser($user)->count(2)->create();

        $user->delete();

        expect(PlannerEntry::count())->toBe(0);
    });
});
