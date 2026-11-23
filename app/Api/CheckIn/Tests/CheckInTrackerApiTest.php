<?php

use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Services\CheckInTrackerService;
use App\Api\Goals\Models\Goal;
use Illuminate\Support\Facades\DB;

describe('GET /api/v1/check-in/trackers', function () {
    it('lists the built-in trackers of a new user in the default order and state', function () {
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/trackers')
            ->assertStatus(200)
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.key', 'mood')
            ->assertJsonPath('data.0.type', 'scale')
            ->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.0.scored', true)
            ->assertJsonPath('data.0.name', null)
            ->assertJsonMissingPath('data.0.goal')
            ->assertJsonMissingPath('data.0.alerts');

        expect(collect($response->json('data'))->where('enabled', true)->pluck('key')->all())
            ->toBe(['mood', 'sleep', 'dreams', 'food_quality', 'food_amount', 'water', 'sports', 'work']);
    });

    it('counts each tracker\'s values', function () {
        $user = makeUser();
        $tracker = ownTracker($user);
        CheckInValue::factory()->forTracker($tracker)->on('2024-01-01', 1)->create();
        CheckInValue::factory()->forTracker($tracker)->on('2026-10-01', 1)->create();

        $data = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in/trackers')->json('data'));

        expect($data->firstWhere('id', $tracker->id)['values_count'])->toBe(2)
            ->and($data->firstWhere('key', 'mood')['values_count'])->toBe(0);
    });

    it('adds missing built-in trackers for a user who has none', function () {
        $user = makeUser();
        DB::table('check_in_trackers')->where('user_id', $user->id)->delete();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/trackers')
            ->assertStatus(200)
            ->assertJsonCount(12, 'data');
    });

    it('adds a newly missing built-in tracker at the end without touching the others', function () {
        $user = makeUser();
        $mood = builtInTracker($user, 'mood');
        $mood->update(['enabled' => false]);
        builtInTracker($user, 'social_life')->delete();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in/trackers')->assertStatus(200);

        expect(collect($response->json('data'))->last()['key'])->toBe('social_life')
            ->and($mood->fresh()->enabled)->toBeFalse();
    });

    it('does not list another user\'s trackers', function () {
        $user = makeUser();
        $other = makeUser();
        ownTracker($other);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/trackers')
            ->assertJsonCount(12, 'data');
    });
});

describe('POST /api/v1/check-in/trackers', function () {
    it('refuses another own tracker at the limit without blaming a field', function () {
        $user = makeUser();
        CheckInTracker::factory()->count(CheckInTrackerService::MAX_CUSTOM_TRACKERS)->forUser($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/check-in/trackers', ['name' => 'One more', 'type' => 'yes_no', 'icon' => 'flower-2']);

        expectApiError($response, 422);
        $response->assertJsonPath('message', 'You have reached the maximum number of trackers.')
            ->assertJsonPath('errors', ['limit_reached']);
    });

    it('creates an own tracker at the end, enabled, and scored only for a 1–5 tracker', function (string $type, bool $scored) {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in/trackers', ['name' => 'Meditated', 'type' => $type, 'icon' => 'flower-2'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Meditated')
            ->assertJsonPath('data.key', null)
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.icon', 'flower-2')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.scored', $scored)
            ->assertJsonPath('data.position', 12);
    })->with([['yes_no', false], ['count', false], ['scale', true]]);

    it('rejects a missing name, an unknown type, the sport type and a malformed icon', function (array $payload) {
        $user = makeUser();

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/check-in/trackers', $payload), 422);
    })->with([
        'no name' => [['type' => 'yes_no']],
        'unknown type' => [['name' => 'X', 'type' => 'percent']],
        'sport type' => [['name' => 'X', 'type' => 'sport']],
        'too long name' => [['name' => str_repeat('a', 51), 'type' => 'count']],
        'bad icon' => [['name' => 'X', 'type' => 'count', 'icon' => '../evil']],
    ]);

    it('limits the number of own trackers', function () {
        $user = makeUser();
        CheckInTracker::factory()->forUser($user)->count(50)->create();

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in/trackers', ['name' => 'One more', 'type' => 'count']), 422);
    });
});

describe('PUT /api/v1/check-in/trackers/{tracker}', function () {
    it('renames an own tracker and changes its icon', function () {
        $user = makeUser();
        $tracker = ownTracker($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/trackers/{$tracker->id}", ['name' => 'Yoga', 'icon' => 'flower'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Yoga')
            ->assertJsonPath('data.icon', 'flower');
    });

    it('ignores a name and icon for built-in trackers but turns them on and off', function () {
        $user = makeUser();
        $mood = builtInTracker($user, 'mood');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/trackers/{$mood->id}", ['name' => 'Feelings', 'icon' => 'heart', 'enabled' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.icon', null)
            ->assertJsonPath('data.enabled', false);
    });

    it('keeps the old settings in step when a built-in tracker is turned off', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/check-in/trackers/'.builtInTracker($user, 'sleep')->id, ['enabled' => false])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users/me/settings/check-in')
            ->assertJsonPath('data.enabled_trackers', ['mood', 'dreams', 'food_quality', 'food_amount', 'water', 'sports', 'work']);
        expect($user->fresh()->settings->check_in_settings['enabled_trackers'])->not->toContain('sleep');
    });

    it('only lets 1–5 trackers count towards the score', function () {
        $user = makeUser();
        $tracker = ownTracker($user, CheckInTrackerType::Count);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/trackers/{$tracker->id}", ['scored' => true]), 422);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/check-in/trackers/'.builtInTracker($user, 'mood')->id, ['scored' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.scored', false);
    });

    it('forbids changing another user\'s tracker', function () {
        $user = makeUser();
        $tracker = ownTracker(makeUser());

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/trackers/{$tracker->id}", ['name' => 'Mine now']), 403);
        expect($tracker->fresh()->name)->not->toBe('Mine now');
    });
});

describe('DELETE /api/v1/check-in/trackers/{tracker}', function () {
    it('deletes an own tracker with its values, goals and alerts', function () {
        $user = makeUser();
        $tracker = ownTracker($user);
        CheckInValue::factory()->forTracker($tracker)->on('2026-10-01', 1)->create();
        $goal = Goal::factory()->forTracker($tracker)->create();
        Alert::factory()->forTracker($tracker)->create();
        Alert::factory()->forGoal($goal)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/check-in/trackers/{$tracker->id}")
            ->assertStatus(200);

        expect(CheckInTracker::find($tracker->id))->toBeNull()
            ->and(CheckInValue::where('check_in_tracker_id', $tracker->id)->count())->toBe(0)
            ->and(Goal::where('check_in_tracker_id', $tracker->id)->count())->toBe(0)
            ->and(Alert::where('user_id', $user->id)->count())->toBe(0);
    });

    it('no longer lists the deleted tracker\'s values, even after they were cached', function () {
        $user = makeUser();
        $tracker = ownTracker($user);
        CheckInValue::factory()->forTracker($tracker)->on('2026-10-01', 1)->create();
        $values = fn () => collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in/values')->assertOk()->json('data'));
        expect($values()->where('tracker_id', $tracker->id))->toHaveCount(1);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/check-in/trackers/{$tracker->id}")->assertOk();

        expect($values()->where('tracker_id', $tracker->id))->toHaveCount(0);
    });

    it('refuses to delete a built-in tracker', function () {
        $user = makeUser();
        $mood = builtInTracker($user, 'mood');

        expectApiError($this->actingAs($user, 'sanctum')->deleteJson("/api/v1/check-in/trackers/{$mood->id}"), 422);
        expect($mood->fresh())->not->toBeNull();
    });

    it('forbids deleting another user\'s tracker', function () {
        $user = makeUser();
        $tracker = ownTracker(makeUser());

        expectApiError($this->actingAs($user, 'sanctum')->deleteJson("/api/v1/check-in/trackers/{$tracker->id}"), 403);
        expect($tracker->fresh())->not->toBeNull();
    });
});

describe('PUT /api/v1/check-in/trackers/order', function () {
    it('stores the new order', function () {
        $user = makeUser();
        $own = ownTracker($user);
        $ids = CheckInTracker::forUser($user->id)->orderBy('position')->pluck('id')->all();
        $newOrder = [$own->id, ...array_values(array_diff($ids, [$own->id]))];

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/check-in/trackers/order', ['ids' => $newOrder])
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.1.key', 'mood');
    });

    it('rejects an order that leaves out, repeats or adds a tracker', function (string $case) {
        $user = makeUser();
        $ids = CheckInTracker::forUser($user->id)->pluck('id')->all();
        $foreign = ownTracker(makeUser())->id;

        $payload = match ($case) {
            'missing' => array_slice($ids, 1),
            'repeated' => [...$ids, $ids[0]],
            'foreign' => [...$ids, $foreign],
        };

        expectApiError($this->actingAs($user, 'sanctum')->putJson('/api/v1/check-in/trackers/order', ['ids' => $payload]), 422);
    })->with(['missing', 'repeated', 'foreign']);
});
