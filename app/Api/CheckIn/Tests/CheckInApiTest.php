<?php

use App\Api\CheckIn\Models\CheckIn;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Services\CheckInService;

describe('GET /api/v1/check-in', function () {
    it('lists the users check-ins', function () {
        $user = makeUser();
        CheckIn::factory()->forUser($user)->count(3)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    });

    it('does not list another user\'s check-ins', function () {
        $user = makeUser();
        $other = makeUser();
        CheckIn::factory()->forUser($other)->count(2)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
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

    it('does not touch another user\'s check-in for the same date', function () {
        $user = makeUser();
        $other = makeUser();
        $otherCheckIn = CheckIn::factory()->forUser($other)->create([
            'date' => now()->toDateString(),
            'mood' => 2,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', [
                'date' => now()->toDateString(),
                'mood' => 5,
            ])
            ->assertStatus(201);

        expect($otherCheckIn->fresh()->mood)->toBe(2);
        expect(CheckIn::where('user_id', $user->id)->count())->toBe(1);
    });

    it('rejects a missing date', function () {
        $response = $this->actingAs(makeUser(), 'sanctum')->postJson('/api/v1/check-in', ['mood' => 3]);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['date']);
    });
});

describe('GET /api/v1/check-in/marks', function () {
    it('returns check-in dates within the range', function () {
        $user = makeUser();
        CheckInValue::factory()->forTracker(builtInTracker($user, 'mood'))->on('2026-10-06', 3)->create();
        CheckInValue::factory()->forTracker(builtInTracker($user, 'water'))->on('2026-10-08', 2)->create();
        CheckInValue::factory()->forTracker(builtInTracker($user, 'mood'))->on('2026-11-01', 5)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2026-10-01&to=2026-10-31')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', ['2026-10-06', '2026-10-08']);
    });

    it('scopes marks to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        CheckInValue::factory()->forTracker(builtInTracker($other, 'mood'))->on('2026-10-06', 3)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2026-10-01&to=2026-10-31')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    });

    it('rejects a missing or malformed range', function (string $query, array $fields) {
        $response = $this->actingAs(makeUser(), 'sanctum')->getJson("/api/v1/check-in/marks{$query}");

        expectApiError($response, 422);
        $response->assertJsonValidationErrors($fields);
    })->with([
        'no range' => ['', ['from', 'to']],
        'to before from' => ['?from=2026-10-01&to=2026-09-01', ['to']],
        'wrong date format' => ['?from=01.10.2026&to=31.10.2026', ['from', 'to']],
    ]);

    it('rejects a range longer than 366 days', function () {
        $response = $this->actingAs(makeUser(), 'sanctum')->getJson('/api/v1/check-in/marks?from=2025-01-01&to=2026-10-01');

        expectApiError($response, 422);
        $response->assertJsonValidationErrors(['to' => 'The date range must not exceed 366 days.']);
    });

    it('accepts a range of exactly 366 days', function () {
        $this->actingAs(makeUser(), 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2025-10-01&to=2026-10-02')
            ->assertOk();
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

    it('saveDay persists the day in the old shape', function () {
        $user = makeUser();

        $checkIn = app(CheckInService::class)->saveDay($user, now()->toDateString(), ['mood' => 1]);

        expect($checkIn->user_id)->toBe($user->id);
        expect($checkIn->mood)->toBe(1);
    });
});
