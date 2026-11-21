<?php

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Goals\Models\Goal;
use App\Api\Goals\Services\GoalProgressService;

// Wednesday; with Monday weeks the current week is 2026-11-16 – 2026-11-22.
beforeEach(fn () => $this->travelTo('2026-11-18 12:00:00'));

function goalPayload(int $trackerId, array $overrides = []): array
{
    return ['source' => 'check_in_tracker', 'check_in_tracker_id' => $trackerId, 'period' => 'week', 'comparison' => 'at_least', 'target' => 3, ...$overrides];
}

describe('POST /api/v1/goals', function () {
    it('creates a goal with its progress', function () {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC', 'week_start' => 'monday']);
        $tracker = ownTracker($user);
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-16', 1)->create();
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-17', 1)->create();
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-15', 1)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/goals', goalPayload($tracker->id))
            ->assertStatus(201)
            ->assertJsonPath('data.check_in_tracker_id', $tracker->id)
            ->assertJsonPath('data.notify_when_reached', false)
            ->assertJsonPath('data.progress.current', 2)
            ->assertJsonPath('data.progress.met', false)
            ->assertJsonPath('data.progress.period_start', '2026-11-16')
            ->assertJsonPath('data.progress.period_end', '2026-11-22')
            ->assertJsonCount(GoalProgressService::HISTORY_PERIODS, 'data.progress.history')
            ->assertJsonPath('data.progress.history.0.period_start', '2026-11-09');
    });

    it('creates the reached alert when asked to notify', function () {
        $user = makeUser();
        $tracker = ownTracker($user);

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/goals', goalPayload($tracker->id, ['notify_when_reached' => true]))
            ->assertStatus(201)
            ->assertJsonPath('data.notify_when_reached', true)
            ->json('data.id');

        expect(Alert::where('goal_id', $id)->where('rule', AlertRule::GoalReached)->count())->toBe(1);
    });

    it('rejects a target the tracker type cannot reach', function (CheckInTrackerType $type, array $overrides) {
        $user = makeUser();
        $tracker = ownTracker($user, $type);

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/goals', goalPayload($tracker->id, $overrides)), 422);
    })->with([
        'yes/no per day' => [CheckInTrackerType::YesNo, ['period' => 'day', 'target' => 1]],
        'more days than the week' => [CheckInTrackerType::YesNo, ['target' => 8]],
        'part of a day' => [CheckInTrackerType::YesNo, ['target' => 2.5]],
        'scale above 5' => [CheckInTrackerType::Scale, ['target' => 6]],
        'scale below 1' => [CheckInTrackerType::Scale, ['target' => 0.5]],
        'part of a count' => [CheckInTrackerType::Count, ['target' => 1.5]],
    ]);

    it('accepts a fractional average for 1–5 trackers', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/goals', goalPayload(builtInTracker($user, 'mood')->id, ['period' => 'day', 'target' => 3.5]))
            ->assertStatus(201)
            ->assertJsonPath('data.target', 3.5);
    });

    it('allows one goal per tracker', function () {
        $user = makeUser();
        $tracker = ownTracker($user);
        Goal::factory()->forTracker($tracker)->create();

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/goals', goalPayload($tracker->id, ['period' => 'month'])), 422);
        expect(Goal::count())->toBe(1);
    });

        it('rejects another user\'s tracker', function () {
        $user = makeUser();
        $tracker = ownTracker(makeUser());

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/goals', goalPayload($tracker->id)), 422);
        expect(Goal::count())->toBe(0);
    });
});

describe('GET /api/v1/goals', function () {
    it('lists only the user\'s goals', function () {
        $user = makeUser();
        Goal::factory()->forTracker(ownTracker($user))->create();
        Goal::factory()->forTracker(ownTracker(makeUser()))->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/goals')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    });

    it('measures each tracker type its own way', function (CheckInTrackerType $type, array $values, float $target, ?int $current, bool $met) {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC', 'week_start' => 'monday']);
        $tracker = ownTracker($user, $type);
        foreach ($values as $date => $value) {
            CheckInValue::factory()->forTracker($tracker)->on($date, $value)->create();
        }
        Goal::factory()->forTracker($tracker)->create(['target' => $target]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/goals')
            ->assertJsonPath('data.0.progress.current', $current)
            ->assertJsonPath('data.0.progress.met', $met);
    })->with([
        'yes/no counts days' => [CheckInTrackerType::YesNo, ['2026-11-16' => 1, '2026-11-18' => 1], 2, 2, true],
        'count sums' => [CheckInTrackerType::Count, ['2026-11-16' => 4, '2026-11-17' => 3], 8, 7, false],
        'scale averages ratings' => [CheckInTrackerType::Scale, ['2026-11-16' => 2, '2026-11-17' => 4], 3, 3, true],
        'scale without ratings' => [CheckInTrackerType::Scale, [], 3, null, false],
    ]);

    it('starts the week on Sunday when the user does', function () {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC', 'week_start' => 'sunday']);
        Goal::factory()->forTracker(ownTracker($user))->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/goals')
            ->assertJsonPath('data.0.progress.period_start', '2026-11-15')
            ->assertJsonPath('data.0.progress.period_end', '2026-11-21');
    });

    it('marks met and missed past periods', function () {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC', 'week_start' => 'monday']);
        $tracker = ownTracker($user);
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-09', 1)->create();
        Goal::factory()->forTracker($tracker)->create(['period' => 'week', 'target' => 1]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/goals')
            ->assertJsonPath('data.0.progress.history.0.met', true)
            ->assertJsonPath('data.0.progress.history.1.met', false);
    });
});

describe('PUT /api/v1/goals/{goal}', function () {
    it('updates a goal and switches the reached alert on and off', function () {
        $user = makeUser();
        $goal = Goal::factory()->forTracker(ownTracker($user))->create();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/goals/{$goal->id}", ['period' => 'month', 'target' => 10, 'notify_when_reached' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.period', 'month')
            ->assertJsonPath('data.notify_when_reached', true);
        expect($goal->reachedAlert()->count())->toBe(1);

        // Switching on twice keeps one alert.
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/goals/{$goal->id}", ['notify_when_reached' => true])->assertStatus(200);
        expect($goal->reachedAlert()->count())->toBe(1);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/goals/{$goal->id}", ['notify_when_reached' => false])
            ->assertJsonPath('data.notify_when_reached', false);
        expect($goal->reachedAlert()->count())->toBe(0);
    });

    it('checks the target against the new period', function () {
        $user = makeUser();
        $goal = Goal::factory()->forTracker(ownTracker($user))->create(['period' => 'month', 'target' => 20]);

        expectApiError($this->actingAs($user, 'sanctum')->putJson("/api/v1/goals/{$goal->id}", ['period' => 'week']), 422);
    });

    it('forbids updating another user\'s goal', function () {
        $goal = Goal::factory()->forTracker(ownTracker(makeUser()))->create();

        expectApiError($this->actingAs(makeUser(), 'sanctum')->putJson("/api/v1/goals/{$goal->id}", ['target' => 1]), 403);
    });
});

describe('DELETE /api/v1/goals/{goal}', function () {
    it('deletes a goal with its reached alert', function () {
        $user = makeUser();
        $goal = Goal::factory()->forTracker(ownTracker($user))->create();
        Alert::factory()->forGoal($goal)->create();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/goals/{$goal->id}")->assertStatus(200);

        expect(Goal::find($goal->id))->toBeNull()
            ->and(Alert::where('goal_id', $goal->id)->count())->toBe(0);
    });

    it('forbids deleting another user\'s goal', function () {
        $goal = Goal::factory()->forTracker(ownTracker(makeUser()))->create();

        expectApiError($this->actingAs(makeUser(), 'sanctum')->deleteJson("/api/v1/goals/{$goal->id}"), 403);
        expect($goal->fresh())->not->toBeNull();
    });
});
