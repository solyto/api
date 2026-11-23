<?php

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\Alerts\Notifications\AlertNotification;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Goals\Enums\GoalComparison;
use App\Api\Goals\Enums\GoalPeriod;
use App\Api\Goals\Models\Goal;
use App\Api\Users\Models\User;
use Illuminate\Support\Facades\Notification;

// 08:00 UTC is 09:00 in Berlin in November.
beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-11-18 08:00:00');
});

// "Nothing logged for 7 days" needs a tracker that existed for those 7 days.
function sportsAlertFor(User $user): Alert
{
    $sports = builtInTracker($user, 'sports');
    $sports->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();

    return Alert::factory()->forTracker($sports)->rule(AlertRule::NoEntryDays, null, 7)->create();
}

function berlinUser(array $settings = []): User
{
    $user = makeUser();
    $user->settings()->update(['timezone' => 'Europe/Berlin', ...$settings]);

    return $user;
}

/** @return array{0: string, 1: string} title and body as the in-app notification shows them */
function alertText(AlertNotification $notification, User $user): array
{
    $data = $notification->toDatabase($user);

    return [$data['title'], $data['body']];
}

function runAlertsOn(string ...$dates): void
{
    foreach ($dates as $date) {
        test()->travelTo("{$date} 08:00:00");
        test()->artisan('app:send-alerts');
    }
}

describe('app:send-alerts', function () {
    it('checks users whose local time is 9:00', function () {
        $berlin = berlinUser();
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
        $user = berlinUser();
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
        $user = berlinUser();
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
        $user = berlinUser(['week_start' => 'monday']);
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

    it('says "last week" for an at-least goal reached on the last day of the week', function () {
        $user = berlinUser(['week_start' => 'monday']);
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
        $user = berlinUser();
        sportsAlertFor($user);

        $this->artisan('app:send-alerts');

        Notification::assertSentTo($user, AlertNotification::class, fn (AlertNotification $n) => alertText($n, $user)[0] === 'Check-in');
    });

    it('tells about a kept weekly at-most goal once, the morning after the week ended', function () {
        $user = berlinUser(['week_start' => 'monday']);
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

        foreach (['2026-11-23', '2026-11-24', '2026-11-25'] as $date) {
            CheckInValue::factory()->forTracker($tracker)->on($date, 1)->create();
        }
        runAlertsOn('2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29', '2026-11-30', '2026-12-01');
        Notification::assertSentToTimes($user, AlertNotification::class, 1);

        runAlertsOn('2026-12-07');
        Notification::assertSentToTimes($user, AlertNotification::class, 2);
    });

    it('tells about a reached daily at-least goal every morning', function () {
        $user = berlinUser();
        $tracker = ownTracker($user, CheckInTrackerType::Count);
        $tracker->update(['name' => 'Tracker']);
        $goal = Goal::factory()->forTracker($tracker)->create(['period' => 'day', 'comparison' => 'at_least', 'target' => 8]);
        Alert::factory()->forGoal($goal)->create();
        foreach (['2026-11-17', '2026-11-18', '2026-11-19'] as $date) {
            CheckInValue::factory()->forTracker($tracker)->on($date, 8)->create();
        }

        runAlertsOn('2026-11-18', '2026-11-19', '2026-11-20');
        Notification::assertSentToTimes($user, AlertNotification::class, 3);
        Notification::assertSentTo($user, AlertNotification::class, fn (AlertNotification $n) => alertText($n, $user) === ['Goal reached', 'Tracker: you reached your goal yesterday.']);

        // Nothing logged on the 20th: no notification on the 21st, and none twice on the same day.
        runAlertsOn('2026-11-21');
        $this->artisan('app:send-alerts', ['--user' => $user->email]);
        Notification::assertSentToTimes($user, AlertNotification::class, 3);
    });

    it('tells about a reached weekly at-least goal in consecutive weeks', function () {
        $user = berlinUser(['week_start' => 'monday']);
        $tracker = ownTracker($user);
        $tracker->update(['name' => 'Tracker']);
        $goal = Goal::factory()->forTracker($tracker)->create(['period' => 'week', 'comparison' => 'at_least', 'target' => 1]);
        Alert::factory()->forGoal($goal)->create();
        // Met on the first day of both weeks, so no morning ever sees it unmet.
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-16', 1)->create();
        CheckInValue::factory()->forTracker($tracker)->on('2026-11-23', 1)->create();

        runAlertsOn('2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23');
        Notification::assertSentToTimes($user, AlertNotification::class, 1);

        runAlertsOn('2026-11-24', '2026-11-25');
        Notification::assertSentToTimes($user, AlertNotification::class, 2);
    });

    it('tells about a kept daily at-most goal every morning', function () {
        $user = berlinUser();
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

    it('checks one user right away with --user, whatever their local time', function () {
        $this->travelTo('2026-11-18 15:00:00');
        $user = berlinUser();
        $other = berlinUser();
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

    it('starts an alert fresh after it or its tracker was switched off and on again', function (string $switch) {
        $user = berlinUser();
        $alert = sportsAlertFor($user);
        $off = fn (bool $enabled) => $switch === 'alert' ? $alert->update(['enabled' => $enabled]) : $alert->checkInTracker->update(['enabled' => $enabled]);

        runAlertsOn('2026-11-18');
        Notification::assertSentToTimes($user, AlertNotification::class, 1);

        $off(false);
        runAlertsOn('2026-11-19');
        $off(true);
        runAlertsOn('2026-11-20');
        Notification::assertSentToTimes($user, AlertNotification::class, 2);
    })->with(['alert', 'tracker']);
});

describe('AlertNotification', function () {
    it('sends through the channels switched on for alerts', function () {
        $user = makeUser();
        $user->notificationSettings()->updateOrCreate(['user_id' => $user->id], ['alert_ui' => true, 'alert_push' => false, 'alert_email' => true, 'alert_telegram' => false]);
        $notification = new AlertNotification(AlertRule::NoEntryDays, 'sports', 'Sports', 7);

        expect($notification->via($user->fresh()))->toContain('database', 'mail');

        $user->notificationSettings()->update(['alert_ui' => false, 'alert_email' => false]);
        expect($notification->via($user->fresh()))->not->toContain('database')->not->toContain('mail');
    });

    it('words an at-most goal as looking back, whatever the period state says', function (GoalPeriod $period, string $when) {
        $notification = new AlertNotification(AlertRule::GoalReached, null, 'Coffee', goalComparison: GoalComparison::AtMost, goalPeriod: $period, periodFinished: false);

        expect(alertText($notification, makeUser()))->toBe(['Goal kept', "Coffee: you stayed within your goal {$when}."]);
    })->with([
        'week' => [GoalPeriod::Week, 'last week'],
        'month' => [GoalPeriod::Month, 'last month'],
    ]);

    it('says "day" for a one-day window and "days" for longer ones', function (string $language, AlertRule $rule, string $oneDay, string $sevenDays) {
        $user = makeUser();
        $user->settings()->updateOrCreate(['user_id' => $user->id], ['language' => $language]);
        $text = fn (int $days) => alertText(new AlertNotification($rule, null, 'Coffee', $days, 2, 3), $user->fresh())[1];

        expect($text(1))->toBe($oneDay)
            ->and($text(7))->toBe($sevenDays);
    })->with([
        'en average below' => ['en', AlertRule::AverageBelow, 'Coffee: 3 on average over the last day (below 2).', 'Coffee: 3 on average over the last 7 days (below 2).'],
        'en average above' => ['en', AlertRule::AverageAbove, 'Coffee: 3 on average over the last day (above 2).', 'Coffee: 3 on average over the last 7 days (above 2).'],
        'en no entry' => ['en', AlertRule::NoEntryDays, 'Coffee: nothing logged for 1 day.', 'Coffee: nothing logged for 7 days.'],
        'en entries' => ['en', AlertRule::EntryDays, 'Coffee: logged 1 day in a row.', 'Coffee: logged 7 days in a row.'],
        'de average below' => ['de', AlertRule::AverageBelow, 'Coffee: im Schnitt 3 am letzten Tag (unter 2).', 'Coffee: im Schnitt 3 in den letzten 7 Tagen (unter 2).'],
        'de no entry' => ['de', AlertRule::NoEntryDays, 'Coffee: seit 1 Tag nichts eingetragen.', 'Coffee: seit 7 Tagen nichts eingetragen.'],
        'de entries' => ['de', AlertRule::EntryDays, 'Coffee: 1 Tag in Folge eingetragen.', 'Coffee: 7 Tage in Folge eingetragen.'],
    ]);
});
