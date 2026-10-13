<?php

use App\Api\CheckIn\Models\CheckIn;
use App\Api\CheckIn\Services\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('GET /api/v1/check-in', function () {
    it('lists the users check-ins', function () {
        $user = makeUser();
        CheckIn::factory()->forUser($user)->count(3)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    });
});

describe('POST /api/v1/check-in', function () {
    it('creates a check-in for a new date', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', [
                'date' => now()->toDateString(),
                'mood' => 4,
                'water' => 3,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mood', 4);

        expect(CheckIn::where('user_id', $user->id)->count())->toBe(1);
    });

    it('updates the existing check-in for the same date', function () {
        $user = makeUser();
        CheckIn::factory()->forUser($user)->create([
            'date' => now()->toDateString(),
            'mood' => 2,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', [
                'date' => now()->toDateString(),
                'mood' => 5,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.mood', 5);

        expect(CheckIn::where('user_id', $user->id)->count())->toBe(1);
    });

    it('rejects a missing date', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', ['mood' => 3])
            ->assertStatus(422);
    });
});

describe('GET /api/v1/check-in/marks', function () {
    it('returns check-in dates within the range', function () {
        $user = makeUser();
        CheckIn::factory()->forUser($user)->create(['date' => '2026-10-06']);
        CheckIn::factory()->forUser($user)->create(['date' => '2026-10-08']);
        CheckIn::factory()->forUser($user)->create(['date' => '2026-11-01']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2026-10-01&to=2026-10-31')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', ['2026-10-06', '2026-10-08']);
    });

    it('scopes marks to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        CheckIn::factory()->forUser($other)->create(['date' => '2026-10-06']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2026-10-01&to=2026-10-31')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    });

    it('rejects a missing or malformed range', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks')
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2026-10-01&to=2026-09-01')
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=01.10.2026&to=31.10.2026')
            ->assertStatus(422);
    });

    it('rejects a range longer than 366 days', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2025-01-01&to=2026-10-01')
            ->assertStatus(422);
    });
});

describe('CheckInService', function () {
    it('find returns null for a date without a check-in', function () {
        $user = makeUser();

        expect(app(CheckInService::class)->find($user, now()->toDateString()))->toBeNull();
    });

    it('find returns the check-in for the date', function () {
        $user = makeUser();
        $checkIn = CheckIn::factory()->forUser($user)->create(['date' => now()->toDateString()]);

        $found = app(CheckInService::class)->find($user, now()->toDateString());

        expect($found->id)->toBe($checkIn->id);
    });

    it('create persists the check-in', function () {
        $user = makeUser();

        $checkIn = app(CheckInService::class)->create($user, [
            'date' => now()->toDateString(),
            'mood' => 1,
        ]);

        expect($checkIn->user_id)->toBe($user->id);
        expect($checkIn->mood)->toBe(1);
    });
});
