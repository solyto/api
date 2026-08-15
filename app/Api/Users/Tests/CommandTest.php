<?php

use App\Api\Users\Models\UserNotificationSettings;
use App\Api\Users\Notifications\DailyCheckInReminderNotification;
use App\Api\Users\Notifications\DailyDayReminderNotification;
use App\Dav\Models\Principal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

describe('User management commands', function () {
    it('lists users', function () {
        makeUser(['name' => 'Alice', 'email' => 'alice@example.com']);
        makeUser(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->artisan('app:users:list')
            ->expectsOutputToContain('Alice')
            ->expectsOutputToContain('bob@example.com')
            ->assertSuccessful();
    });

    it('searches users', function () {
        makeUser(['name' => 'Dave', 'email' => 'dave@example.com']);
        makeUser(['name' => 'Eve', 'email' => 'eve@example.com']);

        $this->artisan('app:users:search')
            ->expectsQuestion('Search query (Name, email or UID)', 'dave')
            ->assertSuccessful();
    });

});

describe('DAV commands', function () {
    it('creates principals for existing users', function () {
        $user = makeUser(['email' => 'principal@example.com']);

        $this->artisan('app:dav:create-principals')->assertSuccessful();

        $principal = Principal::where('email', $user->email)->first();
        expect($principal)->not->toBeNull()
            ->and($principal->uri)->toBe('principals/'.$user->email);
    });

    it('skips users that already have a principal', function () {
        $user = makeUser(['email' => 'existing@example.com']);
        Principal::create(['uri' => 'principals/'.$user->email, 'email' => $user->email]);

        $this->artisan('app:dav:create-principals')->assertSuccessful();

        expect(Principal::where('email', $user->email)->count())->toBe(1);
    });

    it('resets the dav data for a user', function () {
        makeUser(['email' => 'reset@example.com']);

        $this->artisan('app:dav:reset-user')
            ->expectsQuestion('Email', 'reset@example.com')
            ->assertSuccessful();
    });
});

describe('Notification command', function () {
    it('sends a test notification', function () {
        Notification::fake();
        $user = makeUser(['email' => 'notify@example.com']);

        Artisan::call('app:send-test-notification', ['email' => 'notify@example.com']);

        Notification::assertSentTo($user, \App\Shared\Notifications\TestNotification::class);
    });
});

describe('Telegram bot commands', function () {
    beforeEach(function () {
        config()->set('telegram.bots.solyto.telegram_token', 'test-token');
        config()->set('telegram.bots.solyto.commands', [['command' => '/start', 'description' => 'Start']]);
        config()->set('telegram.bots.solyto.webhook_url', 'https://example.com/webhook');
        config()->set('telegram.bots.solyto.webhook_token', 'tok123');
        \Illuminate\Support\Facades\Http::fake();
    });

    it('registers the bot with telegram', function () {
        $this->artisan('bots:register:telegram')
            ->expectsChoice('Bot', 'solyto', ['solyto'])
            ->assertSuccessful();
    });

    it('shows the telegram bot status', function () {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['ok' => true])]);

        $this->artisan('bots:status:telegram')
            ->expectsChoice('Bot', 'solyto', ['solyto'])
            ->assertSuccessful();
    });
});

describe('Daily reminder commands', function () {
    afterEach(function () {
        Carbon::setTestNow();
    });

    it('sends daily day reminders at 07:00', function () {
        Notification::fake();
        Carbon::setTestNow('2026-08-14 07:00:00');

        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC']);
        UserNotificationSettings::factory()->forUser($user)->create();

        $this->artisan('app:send-daily-day-reminders')->assertSuccessful();

        Notification::assertSentTo($user, DailyDayReminderNotification::class);
    });

    it('sends daily check-in reminders at 20:00', function () {
        Notification::fake();
        Carbon::setTestNow('2026-08-14 20:00:00');

        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC']);
        UserNotificationSettings::factory()->forUser($user)->create();

        $this->artisan('app:send-daily-check-in-reminders')->assertSuccessful();

        Notification::assertSentTo($user, DailyCheckInReminderNotification::class);
    });

    it('does not send day reminders outside 07:00', function () {
        Notification::fake();
        Carbon::setTestNow('2026-08-14 12:00:00');

        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC']);
        UserNotificationSettings::factory()->forUser($user)->create();

        $this->artisan('app:send-daily-day-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('does not send check-in reminders outside 20:00', function () {
        Notification::fake();
        Carbon::setTestNow('2026-08-14 09:00:00');

        $user = makeUser();
        $user->settings()->update(['timezone' => 'UTC']);
        UserNotificationSettings::factory()->forUser($user)->create();

        $this->artisan('app:send-daily-check-in-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    });
});
