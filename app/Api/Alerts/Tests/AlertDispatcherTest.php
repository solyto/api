<?php

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\Alerts\Notifications\AlertNotification;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Goals\Models\Goal;
use App\Api\Users\Models\User;
use Illuminate\Support\Facades\Notification;

// 08:00 UTC is 09:00 in Berlin in November.
beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-11-18 08:00:00');
});

function sportsAlertFor(User $user): Alert
{
    return Alert::factory()->forTracker(builtInTracker($user, 'sports'))->rule(AlertRule::NoEntryDays, null, 7)->create();
}

it('checks users whose local time is 9:00', function () {
    $berlin = makeUser();
    $berlin->settings()->update(['timezone' => 'Europe/Berlin']);
    $utc = makeUser();
    $utc->settings()->update(['timezone' => 'UTC']);
    $berlinAlert = sportsAlertFor($berlin);
    $utcAlert = sportsAlertFor($utc);

    $this->artisan('app:send-alerts')->assertSuccessful();

    Notification::assertSentTo($berlin, AlertNotification::class, fn (AlertNotification $n) => $n->rule === AlertRule::NoEntryDays && $n->trackerKey === 'sports');
    Notification::assertNotSentTo($utc, AlertNotification::class);
    expect($berlinAlert->fresh())->is_triggered->toBeTrue()
        ->and($berlinAlert->fresh()->last_triggered_on->toDateString())->toBe('2026-11-18')
        ->and($utcAlert->fresh()->is_triggered)->toBeFalse();
});

it('treats users without a timezone as UTC', function () {
    $this->travelTo('2026-11-18 09:00:00');
    $user = makeUser();
    $user->settings()->delete();
    sportsAlertFor($user);

    $this->artisan('app:send-alerts')->assertSuccessful();

    Notification::assertSentTo($user, AlertNotification::class);
});

it('alerts once while the rule holds and again only after it stopped holding', function () {
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin']);
    $alert = sportsAlertFor($user);

    $this->artisan('app:send-alerts');
    $this->travelTo('2026-11-19 08:00:00');
    $this->artisan('app:send-alerts');
    Notification::assertSentToTimes($user, AlertNotification::class, 1);

    // Sport on the 19th: the rule stops holding on the 20th.
    CheckInValue::factory()->forTracker(builtInTracker($user, 'sports'))->on('2026-11-19', 1)->create();
    $this->travelTo('2026-11-20 08:00:00');
    $this->artisan('app:send-alerts');
    expect($alert->fresh()->is_triggered)->toBeFalse();

    $this->travelTo('2026-11-27 08:00:00');
    $this->artisan('app:send-alerts');
    Notification::assertSentToTimes($user, AlertNotification::class, 2);
});

it('skips switched-off alerts and switched-off trackers', function () {
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin']);
    Alert::factory()->forTracker(builtInTracker($user, 'sports'))->create(['enabled' => false]);
    $mood = builtInTracker($user, 'mood');
    $mood->update(['enabled' => false]);
    Alert::factory()->forTracker($mood)->rule(AlertRule::AverageBelow, 3, 7)->create();
    CheckInValue::factory()->forTracker($mood)->on('2026-11-17', 1)->create();
    $off = ownTracker($user);
    $off->update(['enabled' => false]);
    Alert::factory()->forGoal(Goal::factory()->forTracker($off)->create(['target' => 0]))->create();

    $this->artisan('app:send-alerts');

    Notification::assertNothingSent();
});

it('tells when a goal was reached on the completed days', function () {
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin', 'week_start' => 'monday']);
    $tracker = ownTracker($user);
    $tracker->update(['name' => 'Tracker']);
    $goal = Goal::factory()->forTracker($tracker)->create(['period' => 'week', 'target' => 2]);
    Alert::factory()->forGoal($goal)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-16', 1)->create();
    // Today doesn't count yet.
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-18', 1)->create();

    $this->artisan('app:send-alerts');
    Notification::assertNothingSent();

    CheckInValue::factory()->forTracker($tracker)->on('2026-11-17', 1)->create();
    $this->travelTo('2026-11-19 08:00:00');
    $this->artisan('app:send-alerts');
    Notification::assertSentTo($user, AlertNotification::class, fn (AlertNotification $n) => $n->rule === AlertRule::GoalReached
        && alertText($n, $user) === ['Goal reached', 'Tracker: you reached your goal for this week.']);
});

/** @return array{0: string, 1: string} title and body as the in-app notification shows them */
function alertText(AlertNotification $notification, User $user): array
{
    $data = $notification->toDatabase($user);

    return [$data['title'], $data['body']];
}

it('says "last week" for an at-least goal reached on the last day of the week', function () {
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin', 'week_start' => 'monday']);
    $tracker = ownTracker($user);
    $tracker->update(['name' => 'Tracker']);
    $goal = Goal::factory()->forTracker($tracker)->create(['period' => 'week', 'target' => 2]);
    Alert::factory()->forGoal($goal)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-21', 1)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-22', 1)->create();

    runAlertsOn('2026-11-23');

    Notification::assertSentTo($user, AlertNotification::class, fn (AlertNotification $n) => alertText($n, $user) === ['Goal reached', 'Tracker: you reached your goal last week.']);
});

it('titles tracker alerts "Check-in"', function () {
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin']);
    sportsAlertFor($user);

    $this->artisan('app:send-alerts');

    Notification::assertSentTo($user, AlertNotification::class, fn (AlertNotification $n) => alertText($n, $user)[0] === 'Check-in');
});

function runAlertsOn(string ...$dates): void
{
    foreach ($dates as $date) {
        test()->travelTo("{$date} 08:00:00");
        test()->artisan('app:send-alerts');
    }
}

it('tells about a kept weekly at-most goal once, the morning after the week ended', function () {
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin', 'week_start' => 'monday']);
    $tracker = ownTracker($user);
    $tracker->update(['name' => 'Tracker']);
    $goal = Goal::factory()->forTracker($tracker)->create(['period' => 'week', 'comparison' => 'at_most', 'target' => 2]);
    Alert::factory()->forGoal($goal)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-16', 1)->create();

    // The week of the 16th is still running: nothing yet, although it's within the limit so far.
    runAlertsOn('2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22');
    Notification::assertNothingSent();

    runAlertsOn('2026-11-23', '2026-11-24', '2026-11-25');
    Notification::assertSentToTimes($user, AlertNotification::class, 1);
    Notification::assertSentTo($user, AlertNotification::class, fn (AlertNotification $n) => alertText($n, $user) === ['Goal kept', 'Tracker: you stayed within your goal last week.']);

    // The next week goes over the limit: no notification for it.
    foreach (['2026-11-23', '2026-11-24', '2026-11-25'] as $date) {
        CheckInValue::factory()->forTracker($tracker)->on($date, 1)->create();
    }
    runAlertsOn('2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29', '2026-11-30', '2026-12-01');
    Notification::assertSentToTimes($user, AlertNotification::class, 1);

    // The week after is kept again.
    runAlertsOn('2026-12-07');
    Notification::assertSentToTimes($user, AlertNotification::class, 2);
});

it('tells about a kept daily at-most goal every morning', function () {
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin']);
    $tracker = ownTracker($user, CheckInTrackerType::Count);
    $tracker->update(['name' => 'Tracker']);
    $goal = Goal::factory()->forTracker($tracker)->create(['period' => 'day', 'comparison' => 'at_most', 'target' => 2]);
    Alert::factory()->forGoal($goal)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-17', 1)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-18', 5)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-19', 2)->create();
    CheckInValue::factory()->forTracker($tracker)->on('2026-11-20', 2)->create();

    // Kept on the 17th, missed on the 18th, kept on the 19th and the 20th.
    runAlertsOn('2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21');
    Notification::assertSentToTimes($user, AlertNotification::class, 3);
    Notification::assertSentTo($user, AlertNotification::class, fn (AlertNotification $n) => alertText($n, $user) === ['Goal kept', 'Tracker: you stayed within your goal yesterday.']);

    // Checking the same user again on the same day doesn't repeat it.
    $this->artisan('app:send-alerts', ['--user' => $user->email]);
    Notification::assertSentToTimes($user, AlertNotification::class, 3);
});

it('sends through the channels switched on for alerts', function () {
    $user = makeUser();
    $user->notificationSettings()->updateOrCreate(['user_id' => $user->id], ['alert_ui' => true, 'alert_push' => false, 'alert_email' => true, 'alert_telegram' => false]);
    $notification = new AlertNotification(AlertRule::NoEntryDays, 'sports', 'Sports', 7);

    expect($notification->via($user->fresh()))->toContain('database', 'mail');

    $user->notificationSettings()->update(['alert_ui' => false, 'alert_email' => false]);
    expect($notification->via($user->fresh()))->not->toContain('database')->not->toContain('mail');
});

it('checks one user right away with --user, whatever their local time', function () {
    $this->travelTo('2026-11-18 15:00:00');
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin']);
    $other = makeUser();
    $other->settings()->update(['timezone' => 'Europe/Berlin']);
    sportsAlertFor($user);
    sportsAlertFor($other);

    $this->artisan('app:send-alerts', ['--user' => $user->email])->expectsOutput('Sent 1 alert(s)')->assertSuccessful();

    Notification::assertSentTo($user, AlertNotification::class);
    Notification::assertNotSentTo($other, AlertNotification::class);
});

it('fails for an unknown --user', function () {
    $this->artisan('app:send-alerts', ['--user' => 'nobody@example.org'])->assertFailed();
});

it('runs hourly with an overlap lock that expires after an hour', function () {
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn (\Illuminate\Console\Scheduling\Event $event) => str_contains((string) $event->command, 'app:send-alerts'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});
