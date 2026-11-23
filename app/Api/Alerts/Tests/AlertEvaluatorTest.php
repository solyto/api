<?php

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\Alerts\Services\AlertEvaluator;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use Carbon\CarbonImmutable;

// Judges the days before 2026-11-18 only.
function evaluateAlert(Alert $alert): array
{
    $alert->load(['checkInTracker', 'goal.checkInTracker', 'user.settings']);

    return app(AlertEvaluator::class)->evaluate(collect([$alert]), [$alert->user_id => CarbonImmutable::parse('2026-11-18')])[$alert->id];
}

function trackerWithValues(CheckInTrackerType $type, array $values, string $createdAt = '2026-01-01 00:00:00')
{
    $tracker = ownTracker(makeUser(), $type);
    $tracker->forceFill(['created_at' => $createdAt])->save();
    foreach ($values as $date => $value) {
        CheckInValue::factory()->forTracker($tracker)->on($date, $value)->create();
    }

    return $tracker;
}

it('sees no entries in the window, ignoring today', function () {
    $tracker = trackerWithValues(CheckInTrackerType::Sport, ['2026-11-10' => 1, '2026-11-18' => 1]);
    $alert = Alert::factory()->forTracker($tracker)->rule(AlertRule::NoEntryDays, null, 7)->create();

    expect(evaluateAlert($alert))->toMatchArray(['active' => true, 'last_entry' => '2026-11-10', 'streak' => 0]);
});

it('stops seeing no entries once a day in the window has one', function () {
    $tracker = trackerWithValues(CheckInTrackerType::Sport, ['2026-11-11' => 1]);
    $alert = Alert::factory()->forTracker($tracker)->rule(AlertRule::NoEntryDays, null, 7)->create();

    expect(evaluateAlert($alert)['active'])->toBeFalse();
});

it('sees entries on every day of the window', function () {
    $tracker = trackerWithValues(CheckInTrackerType::YesNo, ['2026-11-15' => 1, '2026-11-16' => 1, '2026-11-17' => 1]);

    expect(evaluateAlert(Alert::factory()->forTracker($tracker)->rule(AlertRule::EntryDays, null, 3)->create()))
        ->toMatchArray(['active' => true, 'streak' => 3])
        ->and(evaluateAlert(Alert::factory()->forTracker($tracker)->rule(AlertRule::EntryDays, null, 4)->create())['active'])
        ->toBeFalse();
});

it('averages counts per day, counting days without an entry as 0', function () {
    $tracker = trackerWithValues(CheckInTrackerType::Count, ['2026-11-17' => 8, '2026-11-16' => 4]);
    $alert = Alert::factory()->forTracker($tracker)->rule(AlertRule::AverageBelow, 5, 4)->create();

    expect(evaluateAlert($alert))->toMatchArray(['active' => true, 'average' => 3.0]);
});

it('averages 1–5 over the ratings given', function () {
    $tracker = trackerWithValues(CheckInTrackerType::Scale, ['2026-11-17' => 4, '2026-11-16' => 0, '2026-11-15' => 3]);

    expect(evaluateAlert(Alert::factory()->forTracker($tracker)->rule(AlertRule::AverageBelow, 3, 7)->create()))
        ->toMatchArray(['active' => false, 'average' => 3.5])
        ->and(evaluateAlert(Alert::factory()->forTracker($tracker)->rule(AlertRule::AverageAbove, 3, 7)->create())['active'])
        ->toBeTrue();
});

it('does not judge a 1–5 average without ratings', function () {
    $tracker = trackerWithValues(CheckInTrackerType::Scale, []);
    $alert = Alert::factory()->forTracker($tracker)->rule(AlertRule::AverageBelow, 3, 7)->create();

    expect(evaluateAlert($alert))->toMatchArray(['active' => false, 'average' => null]);
});

// A tracker created during the window would otherwise count the days before it existed as empty.
it('doesn\'t see "nothing logged" before the tracker existed for the whole window', function () {
    $tracker = trackerWithValues(CheckInTrackerType::YesNo, [], '2026-11-15 10:00:00');
    $alert = Alert::factory()->forTracker($tracker)->rule(AlertRule::NoEntryDays, null, 7)->create();

    expect(evaluateAlert($alert)['active'])->toBeFalse();
});

it('doesn\'t judge a count average before the tracker existed for the whole window', function () {
    $tracker = trackerWithValues(CheckInTrackerType::Count, ['2026-11-16' => 2], '2026-11-15 10:00:00');
    $alert = Alert::factory()->forTracker($tracker)->rule(AlertRule::AverageBelow, 5, 7)->create();

    expect(evaluateAlert($alert)['active'])->toBeFalse();
});

it('sees "nothing logged" once the tracker existed for the whole window', function () {
    // Created on the window's first day (the 11th, for 7 days before the 18th).
    $tracker = trackerWithValues(CheckInTrackerType::YesNo, [], '2026-11-11 10:00:00');
    $alert = Alert::factory()->forTracker($tracker)->rule(AlertRule::NoEntryDays, null, 7)->create();

    expect(evaluateAlert($alert)['active'])->toBeTrue();
});
