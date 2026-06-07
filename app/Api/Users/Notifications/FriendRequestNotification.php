<?php

namespace App\Api\Users\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class FriendRequestNotification extends BaseNotification
{
    public function __construct(
        public string $name,
    ) {}

    protected function getNotificationType(): string
    {
        return 'friend_request';
    }

    public function databaseType(object $notifiable): string
    {
        return 'friend_request';
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.friend_request_title'),
            'body'  => __('notifications.friend_request_body', ['name' => $this->name]),
            'link'  => '/profile',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.friend_request_title'))
                ->body(__('notifications.friend_request_body', ['name' => $this->name]))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/profile'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.friend_request_title'))
                ->greeting(__('notifications.friend_request_title'))
                ->line(__('notifications.friend_request_body', ['name' => $this->name]))
                ->action(__('notifications.action_view_profile'), config('app.frontend_url') . '/profile')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.friend_request_title'))
                ->line(__('notifications.friend_request_body', ['name' => $this->name]))
                ->url(config('app.frontend_url') . '/profile', __('notifications.action_view'))
        );
    }
}
