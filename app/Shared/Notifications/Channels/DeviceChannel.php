<?php

namespace App\Shared\Notifications\Channels;

use App\Api\Users\Models\User;
use App\Api\Users\Services\NotificationService;
use Illuminate\Notifications\Notification;
use Throwable;

class DeviceChannel
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function send(User $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toWebPush')) {
            return;
        }

        // Laravel sends a notification's channels one after another; a failure here must not stop the others.
        try {
            $message = $notification->toWebPush($notifiable, $notification)->toArray();

            $this->notificationService->queueForDevices(
                $notifiable,
                $notification->id,
                (string) ($message['title'] ?? ''),
                $message['body'] ?? null,
                $message['data']['url'] ?? null,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
