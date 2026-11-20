<?php

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInAlert;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\CheckIn\Notifications\CheckInAlertNotification;
use App\Api\CheckIn\Services\CheckInAlertService;
use App\Api\Users\Models\User;
use App\Api\Users\Models\UserNotificationSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

function alertUser(string $timezone = 'UTC'): User
{
    $user = makeUser();
    $user->settings()->update(['timezone' => $timezone, 'language' => 'en']);

    return $user->fresh();
}

function alertTracker(User $user, CheckInTrackerType $type = CheckInTrackerType::YesNo, array $attributes = []): CheckInTracker
{
    return CheckInTracker::factory()->forUser($user)->ofType($type)->create(['name' => 'Meditated', ...$attributes]);
}

beforeEach(fn () => Notification::fake());
afterEach(fn () => Carbon::setTestNow());

describe('CheckInAlertService', function () {
    it('reminds once when a yes/no tracker is not ticked at the reminder time', function () {
        Carbon::setTestNow('2026-10-01 20:00:30');
        $user = alertUser();
        $alert = CheckInAlert::factory()->forTracker(alertTracker($user))->at('20:00')->create();

        expect(app(CheckInAlertService::class)->sendDue())->toBe(1)
            ->and(app(CheckInAlertService::class)->sendDue())->toBe(0);

        Notification::assertSentToTimes($user, CheckInAlertNotification::class, 1);
        expect($alert->fresh()->last_sent_on->toDateString())->toBe('2026-10-01');
    });

    it('stays quiet when the tracker is already done', function () {
        Carbon::setTestNow('2026-10-01 20:01:00');
        $user = alertUser();
        $tracker = alertTracker($user);
        CheckInValue::factory()->forTracker($tracker)->on('2026-10-01', 1)->create();
        CheckInAlert::factory()->forTracker($tracker)->at('20:00')->create();

        expect(app(CheckInAlertService::class)->sendDue())->toBe(0);
        Notification::assertNothingSent();
    });

    it('reminds for a count below the target with the current total', function () {
        Carbon::setTestNow('2026-10-01 18:00:00');
        $user = alertUser();
        $tracker = alertTracker($user, CheckInTrackerType::Count, ['name' => 'Glasses of water']);
        CheckInValue::factory()->forTracker($tracker)->on('2026-10-01', 3)->create();
        CheckInAlert::factory()->forTracker($tracker)->at('18:00', 5)->create();

        app(CheckInAlertService::class)->sendDue();

        Notification::assertSentTo($user, CheckInAlertNotification::class, function (CheckInAlertNotification $notification) use ($user) {
            return $notification->current === 3
                && $notification->target === 5
                && $notification->toDatabase($user)['body'] === 'Glasses of water: 3 of 5 so far today.';
        });
    });

    it('stays quiet for a count that reached the target', function () {
        Carbon::setTestNow('2026-10-01 18:00:00');
        $user = alertUser();
        $tracker = alertTracker($user, CheckInTrackerType::Count);
        CheckInValue::factory()->forTracker($tracker)->on('2026-10-01', 5)->create();
        CheckInAlert::factory()->forTracker($tracker)->at('18:00', 5)->create();

        expect(app(CheckInAlertService::class)->sendDue())->toBe(0);
    });

    it('names built-in trackers in the user\'s language', function () {
        Carbon::setTestNow('2026-10-01 21:00:00');
        $user = alertUser();
        $user->settings()->update(['language' => 'de']);
        $mood = CheckInTracker::forUser($user->id)->where('key', 'mood')->firstOrFail();
        CheckInAlert::factory()->forTracker($mood)->at('21:00')->create();

        app(CheckInAlertService::class)->sendDue();

        Notification::assertSentTo($user, CheckInAlertNotification::class, function (CheckInAlertNotification $notification) use ($user) {
            return str_contains($notification->toDatabase($user->fresh())['body'], __('widgets.tracker_mood', [], 'de'));
        });
    });

    it('uses the user\'s timezone', function () {
        Carbon::setTestNow('2026-10-01 18:00:00');
        $berlin = alertUser('Europe/Berlin');
        CheckInAlert::factory()->forTracker(alertTracker($berlin))->at('20:00')->create();
        $utc = alertUser('UTC');
        CheckInAlert::factory()->forTracker(alertTracker($utc))->at('20:00')->create();

        app(CheckInAlertService::class)->sendDue();

        Notification::assertSentTo($berlin, CheckInAlertNotification::class);
        Notification::assertNotSentTo($utc, CheckInAlertNotification::class);
    });

    it('sends within a few minutes after the time, but not before it or much later', function (string $now, bool $sent) {
        Carbon::setTestNow($now);
        $user = alertUser();
        CheckInAlert::factory()->forTracker(alertTracker($user))->at('20:00')->create();

        expect(app(CheckInAlertService::class)->sendDue())->toBe($sent ? 1 : 0);
    })->with([
        'a minute early' => ['2026-10-01 19:59:00', false],
        'on time' => ['2026-10-01 20:00:00', true],
        'four minutes late' => ['2026-10-01 20:04:59', true],
        'five minutes late' => ['2026-10-01 20:05:00', false],
    ]);

    it('skips reminders of disabled trackers', function () {
        Carbon::setTestNow('2026-10-01 20:00:00');
        $user = alertUser();
        CheckInAlert::factory()->forTracker(alertTracker($user, attributes: ['enabled' => false]))->at('20:00')->create();

        expect(app(CheckInAlertService::class)->sendDue())->toBe(0);
    });

    it('reminds again the next day', function () {
        $user = alertUser();
        CheckInAlert::factory()->forTracker(alertTracker($user))->at('20:00')->create();

        Carbon::setTestNow('2026-10-01 20:00:00');
        app(CheckInAlertService::class)->sendDue();
        Carbon::setTestNow('2026-10-02 20:00:00');
        app(CheckInAlertService::class)->sendDue();

        Notification::assertSentToTimes($user, CheckInAlertNotification::class, 2);
    });

    it('sends through the channels the user chose for check-in reminders', function () {
        $user = alertUser();
        UserNotificationSettings::updateOrCreate(['user_id' => $user->id], [
            'check_in_alert_ui' => true,
            'check_in_alert_push' => false,
            'check_in_alert_email' => true,
            'check_in_alert_telegram' => false,
        ]);

        $channels = (new CheckInAlertNotification('2026-10-01', null, 'Meditated'))->via($user->fresh());

        expect($channels)->toBe(['database', 'mail']);
    });
});
