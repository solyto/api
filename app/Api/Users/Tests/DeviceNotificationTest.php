<?php

use App\Api\Users\Jobs\DeleteOldDeviceNotifications;
use App\Api\Users\Models\DeviceNotification;
use App\Api\Users\Models\NotificationDevice;
use App\Api\Users\Models\User;
use App\Api\Users\Notifications\FriendRequestNotification;
use App\Api\Users\Services\NotificationService;
use App\Shared\Notifications\Channels\DeviceChannel;
use App\Shared\Notifications\TestNotification;
use Illuminate\Support\Facades\Exceptions;
use NotificationChannels\WebPush\WebPushChannel;

function phoneUser(bool $push = true, bool $ui = true): User
{
    $user = makeUser();
    $user->notificationSettings()->updateOrCreate(['user_id' => $user->id], [
        'friend_request_ui' => $ui,
        'friend_request_push' => $push,
        'friend_request_email' => false,
        'friend_request_telegram' => false,
    ]);

    return $user->fresh();
}

function registerPhone($test, User $user, string $deviceId = 'phone-1', ?string $token = null)
{
    return bearer($test, $token ?? sanctumToken($user))->postJson('/api/v1/widgets/notifications/devices', ['device_id' => $deviceId]);
}

function pollPhone($test, User $user, int $after, string $deviceId = 'phone-1', ?string $token = null)
{
    return bearer($test, $token ?? sanctumToken($user))->getJson("/api/v1/widgets/notifications?device_id={$deviceId}&after={$after}");
}

describe('Notification channels with a registered phone', function () {
    it('keeps the channels of users without a registered phone as before', function () {
        $user = phoneUser();

        expect((new FriendRequestNotification('Alex'))->via($user))->toBe(['database', WebPushChannel::class]);
    });

    it('adds the device channel last when push is on and a phone is registered', function () {
        $user = phoneUser();
        app(NotificationService::class)->registerDevice($user, 'phone-1');

        expect((new FriendRequestNotification('Alex'))->via($user))->toBe(['database', WebPushChannel::class, DeviceChannel::class]);
    });

    it('sends the test notification to a registered phone as well', function () {
        $user = phoneUser();
        expect((new TestNotification)->via($user))->not->toContain(DeviceChannel::class);

        app(NotificationService::class)->registerDevice($user, 'phone-1');

        expect((new TestNotification)->via($user))->toBe(['database', 'mail', WebPushChannel::class, DeviceChannel::class]);
    });

    it('adds no device channel when push is off for the type', function () {
        $user = phoneUser(push: false);
        app(NotificationService::class)->registerDevice($user, 'phone-1');

        expect((new FriendRequestNotification('Alex'))->via($user))->toBe(['database']);
    });

    it('ignores a phone that has not polled for 30 days', function () {
        $user = phoneUser();
        app(NotificationService::class)->registerDevice($user, 'phone-1');
        NotificationDevice::where('user_id', $user->id)->update(['last_polled_at' => now()->subDays(31)]);

        expect((new FriendRequestNotification('Alex'))->via($user))->not->toContain(DeviceChannel::class);
    });

    it('queues the push message of a notification for the phone', function () {
        $user = phoneUser();
        app(NotificationService::class)->registerDevice($user, 'phone-1');

        $user->notify(new FriendRequestNotification('Alex'));

        $queued = DeviceNotification::sole();
        expect($queued->user_id)->toBe($user->id)
            ->and($queued->title)->toBe(__('notifications.friend_request_title'))
            ->and($queued->body)->toContain('Alex')
            ->and($queued->url)->toEndWith('/profile')
            ->and($queued->notification_id)->toBe($user->notifications()->sole()->id);
    });

    it('does not let a failing device queue stop the notification', function () {
        Exceptions::fake();
        $user = phoneUser();
        app(NotificationService::class)->registerDevice($user, 'phone-1');
        $this->mock(NotificationService::class)->shouldReceive('queueForDevices')->andThrow(new RuntimeException('queue down'));

        $user->notify(new FriendRequestNotification('Alex'));

        expect($user->notifications()->count())->toBe(1);
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('Polling notifications from a phone', function () {
    it('starts after what was queued before the phone registered', function () {
        $user = phoneUser();
        app(NotificationService::class)->registerDevice($user, 'other-phone');
        $user->notify(new FriendRequestNotification('Before'));

        $cursor = registerPhone($this, $user)->json('data.cursor');
        $user->notify(new FriendRequestNotification('After'));
        $response = pollPhone($this, $user, $cursor);

        expectApiSuccess($response);
        expect(array_column($response->json('data.items'), 'body'))->toHaveCount(1)
            ->and($response->json('data.items.0.body'))->toContain('After')
            ->and($response->json('data.cursor'))->toBe($response->json('data.items.0.id'));

        expect(pollPhone($this, $user, $response->json('data.cursor'))->json('data.items'))->toBe([]);
    });

    it('leaves out notifications already read in the app, and keeps push-only ones', function () {
        $user = phoneUser();
        $cursor = registerPhone($this, $user)->json('data.cursor');
        $user->notify(new FriendRequestNotification('Read'));
        $user->notifications()->sole()->markAsRead();
        $user->notificationSettings()->update(['friend_request_ui' => false]);
        $user->refresh()->notify(new FriendRequestNotification('Push only'));

        $items = pollPhone($this, $user, $cursor)->json('data.items');

        expect($items)->toHaveCount(1)->and($items[0]['body'])->toContain('Push only');
    });

    it('keeps the cursor when only notifications already read are new', function () {
        $user = phoneUser();
        $cursor = registerPhone($this, $user)->json('data.cursor');
        $user->notify(new FriendRequestNotification('Alex'));
        $user->unreadNotifications()->update(['read_at' => now()]);

        $response = pollPhone($this, $user, $cursor);

        expect($response->json('data.items'))->toBe([])
            ->and($response->json('data.cursor'))->toBe($cursor);
    });

    it('only returns the user\'s own notifications', function () {
        $user = phoneUser();
        $other = phoneUser();
        $cursor = registerPhone($this, $user)->json('data.cursor');
        registerPhone($this, $other, 'phone-2');
        $other->notify(new FriendRequestNotification('Not yours'));

        expect(pollPhone($this, $user, $cursor)->json('data.items'))->toBe([]);
    });

    it('answers 404 for a phone that is not registered, also after unregistering', function () {
        $user = phoneUser();
        expectApiError(pollPhone($this, $user, 0), 404);

        registerPhone($this, $user);
        expectApiSuccess(bearer($this, sanctumToken($user))->deleteJson('/api/v1/widgets/notifications/devices/phone-1'));

        expectApiError(pollPhone($this, $user, 0), 404);
        expect((new FriendRequestNotification('Alex'))->via($user->fresh()))->not->toContain(DeviceChannel::class);
    });

    it('works with a widget token', function () {
        $user = phoneUser();
        $token = issueWidgetToken($this, sessionToken($user));

        $cursor = registerPhone($this, $user, 'phone-1', $token)->json('data.cursor');
        $user->notify(new FriendRequestNotification('Alex'));

        expect(pollPhone($this, $user, $cursor, 'phone-1', $token)->json('data.items'))->toHaveCount(1);
    });

    it('validates the device id and cursor', function () {
        $user = phoneUser();

        expectApiError(registerPhone($this, $user, 'not a valid id!'), 422);
        expectApiError(bearer($this, sanctumToken($user))->getJson('/api/v1/widgets/notifications?device_id=phone-1'), 422);
    });
});

describe('DeleteOldDeviceNotifications', function () {
    it('deletes queued notifications after 7 days and phones that stopped polling after 30', function () {
        $user = phoneUser();
        $service = app(NotificationService::class);
        $service->registerDevice($user, 'active');
        $service->registerDevice($user, 'gone');
        NotificationDevice::where('device_id', 'gone')->update(['last_polled_at' => now()->subDays(31)]);
        $service->queueForDevices($user, (string) str()->uuid(), 'Old', null, null);
        DeviceNotification::query()->update(['created_at' => now()->subDays(8)]);
        $service->queueForDevices($user, (string) str()->uuid(), 'New', null, null);

        app(DeleteOldDeviceNotifications::class)->handle($service);

        expect(DeviceNotification::pluck('title')->all())->toBe(['New'])
            ->and(NotificationDevice::pluck('device_id')->all())->toBe(['active']);
    });
});
