<?php

use App\Api\Statistics\Services\StatisticsService;
use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('GET /api/v1/statistics/overview', function () {
    it('returns the overview for admins', function () {
        $admin = makeUser(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/statistics/overview')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'users',
                'todos',
                'notes',
                'friends',
                'calendars',
                'contacts',
            ]]);
    });

    it('forbids regular users', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/statistics/overview')
            ->assertStatus(403);
    });
});

describe('StatisticsService', function () {
    it('counts users and todos', function () {
        makeUser();
        $user = makeUser();
        Todo::factory()->forUser($user)->count(2)->create();

        $stats = app(StatisticsService::class)->overview();

        expect($stats['users'])->toBe(2);
        expect($stats['todos'])->toBe(2);
    });

    it('counts the DAV calendars and address books', function () {
        $user = makeUser();

        $stats = app(StatisticsService::class)->overview();

        // The UserObserver creates a default calendar and address book in the
        // shared DAV database.
        expect($stats['calendars'])->toBeGreaterThanOrEqual(1);
        expect($stats['address_books'])->toBeGreaterThanOrEqual(1);
    });
});
