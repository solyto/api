<?php

namespace App\Api\Users\Services;

use App\Api\Users\Models\DeviceNotification;
use App\Api\Users\Models\NotificationDevice;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;

class NotificationService
{
    private const int DEVICE_NOTIFICATION_LIMIT = 100;

    public function listUnread(User $user): Collection
    {
        return $user->unreadNotifications;
    }

    public function markRead(User $user, string $notificationId): void
    {
        $notification = $user->notifications()->findOrFail($notificationId);
        $notification->markAsRead();
    }

    public function markAllRead(User $user): void
    {
        $user->unreadNotifications->markAsRead();
    }

    // Returns the cursor to poll from, so a new device starts with what comes after it.
    public function registerDevice(User $user, string $deviceId): int
    {
        NotificationDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_id' => $deviceId],
            ['last_polled_at' => now()]
        );

        return $this->latestDeviceNotificationId($user);
    }

    public function unregisterDevice(User $user, string $deviceId): void
    {
        NotificationDevice::where('user_id', $user->id)->where('device_id', $deviceId)->delete();
    }

    public function queueForDevices(User $user, string $notificationId, string $title, ?string $body, ?string $url): void
    {
        DeviceNotification::create([
            'user_id' => $user->id,
            'notification_id' => $notificationId,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);
    }

    /** @return array{items: Collection<int, DeviceNotification>, cursor: int} */
    public function deviceNotifications(User $user, string $deviceId, int $after): array
    {
        $device = NotificationDevice::where('user_id', $user->id)->where('device_id', $deviceId)->firstOrFail();
        $device->update(['last_polled_at' => now()]);

        $readIds = $user->notifications()->whereNotNull('read_at')->select('id');
        $items = DeviceNotification::where('user_id', $user->id)
            ->where('id', '>', $after)
            ->whereNotIn('notification_id', $readIds)
            ->orderBy('id')
            ->limit(self::DEVICE_NOTIFICATION_LIMIT)
            ->get();

        // Without new items the cursor stays: entries skipped as read are filtered again next time,
        // and one inserted after the query above isn't jumped over.
        return ['items' => $items, 'cursor' => max($after, $items->max('id') ?? $after)];
    }

    public function deleteOldDeviceNotifications(): void
    {
        DeviceNotification::where('created_at', '<', now()->subDays(DeviceNotification::RETENTION_DAYS))->delete();
        NotificationDevice::where('last_polled_at', '<', now()->subDays(NotificationDevice::ACTIVE_DAYS))->delete();
    }

    private function latestDeviceNotificationId(User $user): int
    {
        return (int) DeviceNotification::where('user_id', $user->id)->max('id');
    }
}
