<?php

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Goals\Models\Goal;

beforeEach(fn () => $this->travelTo('2026-11-18 12:00:00'));

function alertPayload(int $trackerId, array $overrides = []): array
{
    return ['source' => 'check_in_tracker', 'check_in_tracker_id' => $trackerId, 'rule' => 'no_entry_days', 'days' => 7, ...$overrides];
}

describe('POST /api/v1/alerts', function () {
    it('creates an alert with what the rule currently sees', function () {
        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC']);
        $tracker = ownTracker($user, CheckInTrackerType::Count);
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-17', 6)->create();
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-16', 1)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/alerts', alertPayload($tracker->id, ['rule' => 'average_below', 'threshold' => 5, 'days' => 2]))
            ->assertStatus(201)
            ->assertJsonPath('data.rule', 'average_below')
            ->assertJsonPath('data.threshold', 5)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.state.average', 3.5)
            ->assertJsonPath('data.state.active', true);
    });

    it('rejects a rule the tracker type does not offer', function (CheckInTrackerType $type, array $overrides) {
        $user = makeUser();
        $tracker = ownTracker($user, $type);

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/alerts', alertPayload($tracker->id, $overrides)), 422);
        expect(Alert::count())->toBe(0);
    })->with([
        'streak on 1–5' => [CheckInTrackerType::Scale, ['rule' => 'no_entry_days']],
        'average on yes/no' => [CheckInTrackerType::YesNo, ['rule' => 'average_below', 'threshold' => 1]],
        'average without threshold' => [CheckInTrackerType::Count, ['rule' => 'average_above']],
        'more than 90 days' => [CheckInTrackerType::YesNo, ['days' => 91]],
        'a goal rule' => [CheckInTrackerType::YesNo, ['rule' => 'goal_reached']],
    ]);

    it('rejects another user\'s tracker', function () {
        $tracker = ownTracker(makeUser());

        expectApiError($this->actingAs(makeUser(), 'sanctum')->postJson('/api/v1/alerts', alertPayload($tracker->id)), 422);
    });
});

describe('GET /api/v1/alerts', function () {
    it('lists only the user\'s alerts, goal alerts with their goal\'s tracker', function () {
        $user = makeUser();
        $tracker = ownTracker($user);
        Alert::factory()->forTracker($tracker)->create();
        $goal = Goal::factory()->forTracker($tracker)->create();
        Alert::factory()->forGoal($goal)->create();
        Alert::factory()->forTracker(ownTracker(makeUser()))->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/alerts')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.goal_id', $goal->id)
            ->assertJsonPath('data.1.check_in_tracker_id', $tracker->id);
    });
});

describe('PUT /api/v1/alerts/{alert}', function () {
    it('starts a changed rule fresh', function () {
        $user = makeUser();
        $alert = Alert::factory()->forTracker(ownTracker($user, CheckInTrackerType::Count))
            ->rule(AlertRule::AverageBelow, 5)->create(['is_triggered' => true]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/alerts/{$alert->id}", ['days' => 14])->assertStatus(200);
        expect($alert->fresh()->is_triggered)->toBeTrue();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/alerts/{$alert->id}", ['threshold' => 4])
            ->assertJsonPath('data.threshold', 4);
        expect($alert->fresh()->is_triggered)->toBeFalse();
    });

    it('only switches a goal alert on and off', function () {
        $user = makeUser();
        $alert = Alert::factory()->forGoal(Goal::factory()->forTracker(ownTracker($user))->create())->create();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/alerts/{$alert->id}", ['enabled' => false, 'days' => 3])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.rule', 'goal_reached')
            ->assertJsonPath('data.days', null);
    });

    it('forbids changing another user\'s alert', function () {
        $alert = Alert::factory()->forTracker(ownTracker(makeUser()))->create();

        expectApiError($this->actingAs(makeUser(), 'sanctum')->putJson("/api/v1/alerts/{$alert->id}", ['enabled' => false]), 403);
        expectApiError($this->actingAs(makeUser(), 'sanctum')->deleteJson("/api/v1/alerts/{$alert->id}"), 403);
        expect($alert->fresh()->enabled)->toBeTrue();
    });
});

describe('DELETE /api/v1/alerts/{alert}', function () {
    it('deletes an alert; deleting the reached alert switches the goal\'s notify off', function () {
        $user = makeUser();
        $goal = Goal::factory()->forTracker(ownTracker($user))->create();
        $alert = Alert::factory()->forGoal($goal)->create();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/alerts/{$alert->id}")->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/goals')
            ->assertJsonPath('data.0.notify_when_reached', false);
    });
});

describe('Alert suggestions', function () {
    it('suggests alerts for enabled built-in trackers only', function () {
        $user = makeUser();
        builtInTracker($user, 'sleep')->update(['enabled' => false]);

        $keys = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/alerts/suggestions')->assertStatus(200)->json('data'))->pluck('key');

        expect($keys->all())->not->toContain('sleep_trending_down')
            ->and($keys->all())->toContain('no_sport_week', 'mood_trending_down');
    });

    it('switches a suggestion on once and then hides it', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/alerts/suggestions/no_sport_week')
            ->assertStatus(201)
            ->assertJsonPath('data.suggestion', 'no_sport_week')
            ->assertJsonPath('data.rule', 'no_entry_days')
            ->assertJsonPath('data.check_in_tracker_id', builtInTracker($user, 'sports')->id);

        $keys = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/alerts/suggestions')->json('data'))->pluck('key');
        expect($keys->all())->not->toContain('no_sport_week');

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/alerts/suggestions/no_sport_week'), 422);
        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/alerts/suggestions/unknown'), 422);
        expect(Alert::forUser($user->id)->count())->toBe(1);
    });
});
