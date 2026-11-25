<?php

use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Services\CheckInService;

describe('GET /api/v1/check-in', function () {
    it('lists the user\'s days with built-in values in the old shape, oldest first', function () {
        $user = makeUser();
        CheckInValue::factory()->forTracker(builtInTracker($user, 'mood'))->on('2026-10-02', 4)->create();
        CheckInValue::factory()->forTracker(builtInTracker($user, 'water'))->on('2026-10-02', 3)->create();
        CheckInValue::factory()->forTracker(builtInTracker($user, 'sleep'))->on('2026-10-01', 2)->create();
        CheckInValue::factory()->forTracker(CheckInTracker::factory()->forUser($user)->create())->on('2026-10-03', 1)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 20261001)
            ->assertJsonPath('data.0.date', '2026-10-01')
            ->assertJsonPath('data.0.sleep', 2)
            ->assertJsonPath('data.0.mood', null)
            ->assertJsonPath('data.1.date', '2026-10-02')
            ->assertJsonPath('data.1.mood', 4)
            ->assertJsonPath('data.1.water', 3);
    });

    it('does not list another user\'s check-ins', function () {
        $user = makeUser();
        CheckInValue::factory()->forTracker(builtInTracker(makeUser(), 'mood'))->on('2026-10-01', 3)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });
});

describe('POST /api/v1/check-in', function () {
    it('saves a day and answers with it', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', ['date' => '2026-10-01', 'mood' => 4, 'water' => 3])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.date', '2026-10-01')
            ->assertJsonPath('data.mood', 4)
            ->assertJsonPath('data.water', 3);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in')->assertJsonCount(1, 'data');
    });

    it('changes the sent fields of an existing day and keeps the others', function () {
        $user = makeUser();
        app(CheckInService::class)->saveDay($user, '2026-10-01', ['mood' => 2, 'water' => 3]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', ['date' => '2026-10-01', 'mood' => 5])
            ->assertStatus(201)
            ->assertJsonPath('data.mood', 5)
            ->assertJsonPath('data.water', 3);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in')->assertJsonCount(1, 'data');
    });

    it('does not touch another user\'s day', function () {
        $user = makeUser();
        $other = makeUser();
        app(CheckInService::class)->saveDay($other, '2026-10-01', ['mood' => 2]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', ['date' => '2026-10-01', 'mood' => 5])
            ->assertStatus(201);

        $this->actingAs($other, 'sanctum')->getJson('/api/v1/check-in')->assertJsonPath('data.0.mood', 2);
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
    it('saveDay answers with the day in the old shape', function () {
        $user = makeUser();

        $day = app(CheckInService::class)->saveDay($user, '2026-10-01', ['mood' => 1]);

        expect($day->date)->toBe('2026-10-01')
            ->and($day->values)->toBe(['mood' => 1]);
    });

    it('saveDay answers with an empty day when nothing is left on it', function () {
        $user = makeUser();

        $day = app(CheckInService::class)->saveDay($user, '2026-10-01', ['mood' => null]);

        expect($day->date)->toBe('2026-10-01')
            ->and($day->values)->toBe([]);
    });
});
