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

    protected function resolveLocale(object $notifiable): string
    {
        $lang = $notifiable->settings?->language;
        return in_array($lang, ['en', 'de', 'fr', 'es']) ? $lang : 'en';
    }

    protected function withLocale(object $notifiable, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($this->resolveLocale($notifiable));
        $result = $callback();
        app()->setLocale($previous);
        return $result;
    }

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

        $uiKey       = "{$type}_ui";
        $pushKey     = "{$type}_push";
        $emailKey    = "{$type}_email";
        $telegramKey = "{$type}_telegram";

        if ($settings->$uiKey ?? false) {
            $channels[] = 'database';
        }

        if ($settings->$pushKey ?? false) {
            $channels[] = WebPushChannel::class;
        }

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
