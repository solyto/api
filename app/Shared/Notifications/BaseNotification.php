<?php

namespace App\Shared\Notifications;

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserNotificationSettings;
use App\Shared\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;

abstract class BaseNotification extends Notification
{
    use Queueable;

    abstract protected function getNotificationType(): string;

    public function via(object $notifiable): array
    {
        $channels = [];
        $type = $this->getNotificationType();

        if (!$notifiable instanceof User) {
            return ['database'];
        }

        $settings = $notifiable->notificationSettings;

        if (!$settings) {
            $settings = UserNotificationSettings::firstOrCreate(
                ['user_id' => $notifiable->id]
            );
        }

        $uiKey = "{$type}_ui";
        $pushKey = "{$type}_push";
        $telegramKey = "{$type}_telegram";

        if ($settings->$uiKey ?? false) {
            $channels[] = 'database';
        }

        if ($settings->$pushKey ?? false) {
            $channels[] = WebPushChannel::class;
        }

        $emailKey = "{$type}_email";

        if ($settings->$emailKey ?? false) {
            $channels[] = 'mail';
        }

        if ($settings->$telegramKey ?? false) {
            $connection = $notifiable->telegramConnection;
            if ($connection && $connection->is_confirmed) {
                $channels[] = TelegramChannel::class;
            }
        }

        return $channels;
    }
}
