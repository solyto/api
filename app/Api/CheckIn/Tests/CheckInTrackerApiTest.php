<?php

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInAlert;
use App\Api\CheckIn\Models\CheckInGoal;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Users\Models\User;
use Illuminate\Support\Facades\DB;

function builtInTracker(User $user, string $key): CheckInTracker
{
    return CheckInTracker::forUser($user->id)->where('key', $key)->firstOrFail();
}

function ownTracker(User $user, CheckInTrackerType $type = CheckInTrackerType::YesNo): CheckInTracker
{
    return CheckInTracker::factory()->forUser($user)->ofType($type)->create();
}

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
            ->assertJsonPath('data.0.goal', null)
            ->assertJsonPath('data.0.alerts', []);

        expect(collect($response->json('data'))->where('enabled', true)->pluck('key')->all())
            ->toBe(['mood', 'sleep', 'dreams', 'food_quality', 'food_amount', 'water', 'sports', 'work']);
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
    it('deletes an own tracker with its values, goal and reminders', function () {
        $user = makeUser();
        $tracker = ownTracker($user);
        CheckInValue::factory()->forTracker($tracker)->on('2026-10-01', 1)->create();
        CheckInGoal::factory()->forTracker($tracker)->create();
        CheckInAlert::factory()->forTracker($tracker)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/check-in/trackers/{$tracker->id}")
            ->assertStatus(200);

        expect(CheckInTracker::find($tracker->id))->toBeNull()
            ->and(CheckInValue::where('check_in_tracker_id', $tracker->id)->count())->toBe(0)
            ->and(CheckInGoal::where('check_in_tracker_id', $tracker->id)->count())->toBe(0)
            ->and(CheckInAlert::where('check_in_tracker_id', $tracker->id)->count())->toBe(0);
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

describe('Check-in goals', function () {
    it('sets, replaces and removes a tracker\'s goal', function () {
        $user = makeUser();
        $tracker = ownTracker($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/trackers/{$tracker->id}/goal", ['period' => 'week', 'comparison' => 'at_least', 'target' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.goal', ['period' => 'week', 'comparison' => 'at_least', 'target' => 5]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/trackers/{$tracker->id}/goal", ['period' => 'month', 'comparison' => 'at_most', 'target' => 3])
            ->assertJsonPath('data.goal.period', 'month');
        expect(CheckInGoal::where('check_in_tracker_id', $tracker->id)->count())->toBe(1);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/check-in/trackers/{$tracker->id}/goal")
            ->assertStatus(200)
            ->assertJsonPath('data.goal', null);
    });

    it('accepts an average for 1–5 trackers', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/check-in/trackers/'.builtInTracker($user, 'mood')->id.'/goal', ['period' => 'week', 'comparison' => 'at_least', 'target' => 3.5])
            ->assertStatus(200)
            ->assertJsonPath('data.goal.target', 3.5);
    });

    it('rejects a target that the tracker type cannot reach', function (string $type, array $goal) {
        $user = makeUser();
        $tracker = $type === 'sport' ? builtInTracker($user, 'sports') : ownTracker($user, CheckInTrackerType::from($type));

        expectApiError($this->actingAs($user, 'sanctum')->putJson("/api/v1/check-in/trackers/{$tracker->id}/goal", $goal), 422);
    })->with([
        'scale above 5' => ['scale', ['period' => 'week', 'comparison' => 'at_least', 'target' => 6]],
        'yes/no more days than the week has' => ['yes_no', ['period' => 'week', 'comparison' => 'at_least', 'target' => 8]],
        'yes/no on a day above 1' => ['yes_no', ['period' => 'day', 'comparison' => 'at_least', 'target' => 2]],
        'sports more days than the month has' => ['sport', ['period' => 'month', 'comparison' => 'at_least', 'target' => 32]],
        'count with a fraction' => ['count', ['period' => 'day', 'comparison' => 'at_least', 'target' => 2.5]],
        'unknown period' => ['count', ['period' => 'year', 'comparison' => 'at_least', 'target' => 2]],
        'exactly' => ['count', ['period' => 'day', 'comparison' => 'exactly', 'target' => 2]],
    ]);

    it('forbids setting a goal on another user\'s tracker', function () {
        $user = makeUser();
        $tracker = ownTracker(makeUser());

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/trackers/{$tracker->id}/goal", ['period' => 'day', 'comparison' => 'at_least', 'target' => 1]), 403);
    });
});

describe('Check-in reminders', function () {
    it('adds, changes and removes reminders, listed by time', function () {
        $user = makeUser();
        $tracker = ownTracker($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/check-in/trackers/{$tracker->id}/alerts", ['time' => '20:00'])
            ->assertStatus(201);
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/check-in/trackers/{$tracker->id}/alerts", ['time' => '08:30'])
            ->assertStatus(201)
            ->assertJsonPath('data.alerts.0.time', '08:30')
            ->assertJsonPath('data.alerts.1.time', '20:00')
            ->assertJsonPath('data.alerts.0.target', null);

        $alertId = $response->json('data.alerts.1.id');
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/check-in/alerts/{$alertId}", ['time' => '21:15'])
            ->assertStatus(200)
            ->assertJsonPath('data.alerts.1.time', '21:15');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/check-in/alerts/{$alertId}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.alerts');
    });

    it('requires a target for count trackers and refuses one for the others', function () {
        $user = makeUser();
        $count = ownTracker($user, CheckInTrackerType::Count);
        $yesNo = ownTracker($user);

        expectApiError($this->actingAs($user, 'sanctum')->postJson("/api/v1/check-in/trackers/{$count->id}/alerts", ['time' => '18:00']), 422);
        expectApiError($this->actingAs($user, 'sanctum')->postJson("/api/v1/check-in/trackers/{$yesNo->id}/alerts", ['time' => '18:00', 'target' => 3]), 422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/check-in/trackers/{$count->id}/alerts", ['time' => '18:00', 'target' => 5])
            ->assertStatus(201)
            ->assertJsonPath('data.alerts.0.target', 5);
    });

    it('rejects a malformed time', function () {
        $user = makeUser();
        $tracker = ownTracker($user);

        expectApiError($this->actingAs($user, 'sanctum')->postJson("/api/v1/check-in/trackers/{$tracker->id}/alerts", ['time' => '25:00']), 422);
    });

    it('limits the number of reminders per tracker', function () {
        $user = makeUser();
        $tracker = ownTracker($user);
        CheckInAlert::factory()->forTracker($tracker)->count(10)->create();

        expectApiError($this->actingAs($user, 'sanctum')->postJson("/api/v1/check-in/trackers/{$tracker->id}/alerts", ['time' => '07:00']), 422);
    });

    it('forbids touching another user\'s reminders', function () {
        $user = makeUser();
        $tracker = ownTracker(makeUser());
        $alert = CheckInAlert::factory()->forTracker($tracker)->create();

        expectApiError($this->actingAs($user, 'sanctum')->postJson("/api/v1/check-in/trackers/{$tracker->id}/alerts", ['time' => '07:00']), 403);
        expectApiError($this->actingAs($user, 'sanctum')->putJson("/api/v1/check-in/alerts/{$alert->id}", ['time' => '07:00']), 403);
        expectApiError($this->actingAs($user, 'sanctum')->deleteJson("/api/v1/check-in/alerts/{$alert->id}"), 403);
        expect($alert->fresh())->not->toBeNull();
    });
});
