<?php

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckIn;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Users\Models\User;

function valueTracker(User $user, string $key): CheckInTracker
{
    return CheckInTracker::forUser($user->id)->where('key', $key)->firstOrFail();
}

function putValue($test, User $user, int $trackerId, ?int $value, string $date = '2026-10-01')
{
    return $test->actingAs($user, 'sanctum')
        ->putJson('/api/v1/check-in/values', ['tracker_id' => $trackerId, 'date' => $date, 'value' => $value]);
}

describe('PUT /api/v1/check-in/values', function () {
    it('sets, changes and clears an own tracker\'s value', function () {
        $user = makeUser();
        $tracker = CheckInTracker::factory()->forUser($user)->ofType(CheckInTrackerType::Count)->create();

        putValue($this, $user, $tracker->id, 3)
            ->assertStatus(200)
            ->assertJsonPath('data', ['tracker_id' => $tracker->id, 'date' => '2026-10-01', 'value' => 3]);
        putValue($this, $user, $tracker->id, 53)->assertJsonPath('data.value', 53);
        expect(CheckInValue::where('check_in_tracker_id', $tracker->id)->count())->toBe(1);

        putValue($this, $user, $tracker->id, null)
            ->assertStatus(200)
            ->assertJsonPath('data', null);
        expect(CheckInValue::where('check_in_tracker_id', $tracker->id)->count())->toBe(0);
    });

    it('rejects a value that does not fit the tracker type', function (string $type, int $value) {
        $user = makeUser();
        $tracker = CheckInTracker::factory()->forUser($user)->ofType(CheckInTrackerType::from($type))->create();

        expectApiError(putValue($this, $user, $tracker->id, $value), 422);
    })->with([
        'yes/no other than 1' => ['yes_no', 2],
        'yes/no zero' => ['yes_no', 0],
        'scale below 1' => ['scale', 0],
        'scale above 5' => ['scale', 6],
        'negative count' => ['count', -1],
    ]);

    it('rejects another user\'s tracker and a malformed date', function () {
        $user = makeUser();
        $foreign = CheckInTracker::factory()->forUser(makeUser())->create();

        expectApiError(putValue($this, $user, $foreign->id, 1), 422);
        expect(CheckInValue::where('check_in_tracker_id', $foreign->id)->count())->toBe(0);

        $own = CheckInTracker::factory()->forUser($user)->create();
        expectApiError(putValue($this, $user, $own->id, 1, '01.10.2026'), 422);
    });

    it('mirrors built-in values into the old table, so old apps and a rollback see them', function () {
        $user = makeUser();

        putValue($this, $user, valueTracker($user, 'mood')->id, 4)->assertStatus(200);
        putValue($this, $user, valueTracker($user, 'sports')->id, 6)->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-10-01')
            ->assertJsonPath('data.0.mood', 4)
            ->assertJsonPath('data.0.sports', 6);

        putValue($this, $user, valueTracker($user, 'mood')->id, null);
        expect(CheckIn::forUser($user->id)->sole()->mood)->toBeNull();
    });

    it('does not put own trackers into the old table', function () {
        $user = makeUser();
        $tracker = CheckInTracker::factory()->forUser($user)->create();

        putValue($this, $user, $tracker->id, 1)->assertStatus(200);

        expect(CheckIn::forUser($user->id)->count())->toBe(0);
    });
});

describe('GET /api/v1/check-in/values', function () {
    it('lists the values of all of the user\'s trackers, oldest first', function () {
        $user = makeUser();
        $own = CheckInTracker::factory()->forUser($user)->create();
        CheckInValue::factory()->forTracker($own)->on('2026-10-02', 1)->create();
        CheckInValue::factory()->forTracker(valueTracker($user, 'mood'))->on('2026-10-01', 3)->create();
        CheckInValue::factory()->forTracker(CheckInTracker::factory()->forUser(makeUser())->create())->on('2026-10-01', 1)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/values')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-10-01')
            ->assertJsonPath('data.0.value', 3)
            ->assertJsonPath('data.1.tracker_id', $own->id);
    });

    it('reflects a change right away', function () {
        $user = makeUser();
        $tracker = CheckInTracker::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in/values')->assertJsonCount(0, 'data');
        putValue($this, $user, $tracker->id, 1);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in/values')->assertJsonCount(1, 'data');
    });
});

describe('Check-in for apps before the tracker tables', function () {
    it('saves a day through the old endpoint into the trackers', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', ['date' => '2026-10-01', 'mood' => 4, 'social_life' => 2])
            ->assertStatus(201)
            ->assertJsonPath('data.mood', 4)
            ->assertJsonPath('data.social_life', 2)
            ->assertJsonPath('data.water', null);

        expect(CheckInValue::where('check_in_tracker_id', valueTracker($user, 'mood')->id)->value('value'))->toBe(4)
            ->and(CheckInValue::where('check_in_tracker_id', valueTracker($user, 'social_life')->id)->value('value'))->toBe(2);
    });

    it('still answers with a row when a day is saved without values', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/check-in', ['date' => '2026-10-01', 'mood' => null])
            ->assertStatus(201)
            ->assertJsonPath('data.date', '2026-10-01')
            ->assertJsonPath('data.mood', null);
    });

    it('shows only built-in values in the old list, never own trackers', function () {
        $user = makeUser();
        $own = CheckInTracker::factory()->forUser($user)->create();
        putValue($this, $user, $own->id, 1, '2026-10-02');
        putValue($this, $user, valueTracker($user, 'mood')->id, 3, '2026-10-01');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/check-in')->assertJsonCount(1, 'data');

        expect(array_keys($response->json('data.0')))->toBe([
            'id', 'date', 'mood', 'water', 'sports', 'sleep', 'dreams', 'work', 'food_quality', 'food_amount',
            'menstruation', 'alcohol', 'smoking', 'social_life', 'created_at', 'updated_at',
        ]);
    });

    it('turns built-in trackers on and off through the old settings endpoint and leaves own trackers alone', function () {
        $user = makeUser();
        $own = CheckInTracker::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/check-in', [
                'enabled_trackers' => ['social_life', 'mood'],
                'selected_sports' => ['dumbbell', 'bike', 'mountain', 'footprints', 'yoga'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled_trackers', ['mood', 'social_life'])
            ->assertJsonPath('data.selected_sports', ['dumbbell', 'bike', 'mountain', 'footprints', 'yoga']);

        expect(valueTracker($user, 'water')->enabled)->toBeFalse()
            ->and(valueTracker($user, 'social_life')->enabled)->toBeTrue()
            ->and($own->fresh()->enabled)->toBeTrue();
    });

    it('accepts turning every built-in tracker off, now that own trackers can be the only ones', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/users/me/settings/check-in', [
                'enabled_trackers' => [],
                'selected_sports' => ['dumbbell', 'bike', 'mountain', 'footprints', 'yoga'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled_trackers', []);
    });

    it('marks days that only have own tracker values', function () {
        $user = makeUser();
        putValue($this, $user, CheckInTracker::factory()->forUser($user)->create()->id, 1, '2026-10-05');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/check-in/marks?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('data', ['2026-10-05']);
    });
});
