<?php

use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TelegramBotConnection Factory', function () {
    it('creates a valid connection', function () {
        $connection = TelegramBotConnection::factory()->create();

        expect($connection->token)->not()->toBeEmpty();
        expect($connection->is_confirmed)->toBeBoolean();
        expect($connection->chat_id)->toBeNull();
        expect($connection->your_day_alert)->toBeBoolean();
        expect($connection->check_in_alert)->toBeBoolean();
        expect($connection->user_id)->not()->toBeNull();
    });

    it('creates a confirmed connection', function () {
        $connection = TelegramBotConnection::factory()->confirmed()->create();

        expect($connection->is_confirmed)->toBeTrue();
        expect($connection->chat_id)->not()->toBeNull();
        expect($connection->chat_id)->toBeGreaterThan(999999);
    });

    it('creates an unconfirmed connection', function () {
        $connection = TelegramBotConnection::factory()->create();

        expect($connection->is_confirmed)->toBeFalse();
        expect($connection->chat_id)->toBeNull();
    });

    it('creates a connection with your day alert', function () {
        $connection = TelegramBotConnection::factory()->withYourDayAlert()->create();

        expect($connection->your_day_alert)->toBeTrue();
        expect($connection->check_in_alert)->toBeFalse();
    });

    it('creates a connection with check-in alert', function () {
        $connection = TelegramBotConnection::factory()->withCheckInAlert()->create();

        expect($connection->check_in_alert)->toBeTrue();
        expect($connection->your_day_alert)->toBeFalse();
    });

    it('creates a connection with all alerts', function () {
        $connection = TelegramBotConnection::factory()->withAllAlerts()->create();

        expect($connection->your_day_alert)->toBeTrue();
        expect($connection->check_in_alert)->toBeTrue();
    });

    it('creates a connection for user', function () {
        $user = User::factory()->create();
        $connection = TelegramBotConnection::factory()->forUser($user)->create();

        expect($connection->user_id)->toBe($user->id);
    });
});

describe('TelegramBotConnection Model', function () {
    it('has correct fillable attributes', function () {
        $connection = new TelegramBotConnection;

        expect($connection->getFillable())->toContain('token');
        expect($connection->getFillable())->toContain('is_confirmed');
        expect($connection->getFillable())->toContain('chat_id');
        expect($connection->getFillable())->toContain('user_id');
        expect($connection->getFillable())->toContain('your_day_alert');
        expect($connection->getFillable())->toContain('check_in_alert');
    });

    it('casts boolean fields correctly', function () {
        $connection = TelegramBotConnection::factory()->create();

        expect($connection->is_confirmed)->toBeBoolean();
        expect($connection->your_day_alert)->toBeBoolean();
        expect($connection->check_in_alert)->toBeBoolean();
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $connection = TelegramBotConnection::factory()->forUser($user)->create();

        expect($connection->user->id)->toBe($user->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        TelegramBotConnection::factory()->forUser($user1)->create();
        TelegramBotConnection::factory()->forUser($user2)->create();

        $user1Connections = TelegramBotConnection::where('user_id', $user1->id)->get();
        $user2Connections = TelegramBotConnection::where('user_id', $user2->id)->get();

        expect($user1Connections)->toHaveCount(1);
        expect($user2Connections)->toHaveCount(1);
    });

    it('scopes by chat id', function () {
        $user = User::factory()->create();
        $chatId = 123456789;

        TelegramBotConnection::factory()->forUser($user)->create([
            'chat_id' => $chatId,
            'is_confirmed' => true,
        ]);

        $connection = TelegramBotConnection::forChatId($chatId)->first();

        expect($connection->chat_id)->toBe($chatId);
    });
});
