<?php

use App\Api\Notifications\Commands\SendTestNotificationCommand;
use App\Api\Users\Models\User;
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
        makeUser(['email' => 'principal@example.com']);

        $this->artisan('app:dav:create-principals')->assertSuccessful();
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
    it('sends daily day reminders', function () {
        Notification::fake();
        $user = makeUser();
        $user->settings()->update(['first_visit' => false]);

        $this->artisan('app:send-daily-day-reminders')->assertSuccessful();
    });

    it('sends daily check-in reminders', function () {
        Notification::fake();
        $user = makeUser();
        $user->settings()->update(['first_visit' => false]);

        $this->artisan('app:send-daily-check-in-reminders')->assertSuccessful();
    });
});
