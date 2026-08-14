<?php

use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Users\Models\User;
use App\Shared\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;

uses(RefreshDatabase::class);

describe('Notification endpoints', function () {
    it('lists, marks read and marks all read', function () {
        $user = makeUser();
        $user->notify(new TestNotification);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $notification = DatabaseNotification::first();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertStatus(200);

        expect($notification->fresh()->read_at)->not->toBeNull();

        $user->notify(new TestNotification);
        // The `actingAs` user instance caches the unreadNotifications
        // relation, so use a fresh instance for the follow-up request.
        $user->unsetRelation('unreadNotifications');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertStatus(200);

        expect(DatabaseNotification::whereNull('read_at')->count())->toBe(0);
    });

    it('shows and updates notification settings', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/settings')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/notifications/settings', [
                'music_release_ui' => false,
                'music_release_push' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($user->fresh()->notificationSettings->music_release_ui)->toBeFalse();
    });

    it('returns the vapid public key', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/push/vapid-key')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('subscribes and unsubscribes a push subscription', function () {
        $user = makeUser();

        $subscribe = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/push/subscribe', [
                'endpoint' => 'https://push.example.com/sub/abc',
                'keys' => [
                    'p256dh' => 'base64key',
                    'auth' => 'base64auth',
                ],
            ]);

        $subscribe->assertStatus(200)->assertJsonPath('success', true);

        expect(\NotificationChannels\WebPush\PushSubscription::count())->toBe(1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/push/unsubscribe', [
                'endpoint' => 'https://push.example.com/sub/abc',
            ])
            ->assertStatus(200);

        expect(\NotificationChannels\WebPush\PushSubscription::count())->toBe(0);
    });
});

describe('Telegram bot connection endpoints', function () {
    it('creates and returns a token', function () {
        $user = makeUser();

        $first = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/telegram/token-request')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->json('data');

        $second = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/telegram/token-request')
            ->assertStatus(200)
            ->json('data');

        expect($second)->toBe($first);
    });

    it('returns the connection request state', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/telegram/request')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('updates the your-day and check-in alerts', function () {
        $user = makeUser();
        $connection = TelegramBotConnection::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/telegram/your-day-alert', ['your_day_alert' => true])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/telegram/check-in-alert', ['check_in_alert' => false])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($connection->fresh()->your_day_alert)->toBeTrue();
        expect($connection->fresh()->check_in_alert)->toBeFalse();
    });
});
