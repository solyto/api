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
        return [
            'name' => $this->name
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New Friend Request')
            ->body("You have a new Friend Request from {$this->name}.")
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('View Friend Requests', 'view_friend_requests')
            ->data([
                'url' => config('app.frontend_url') . '/profile',
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Friend Request')
            ->greeting('New Friend Request')
            ->line("You have a new friend request from {$this->name}.")
            ->action('View Profile', config('app.frontend_url') . '/profile');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line("New Friend Request")
            ->line("You have a new Friend Request from {$this->name}.")
            ->url(config('app.frontend_url') . '/profile', 'View');
    }
}
