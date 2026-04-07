<?php

namespace App\Api\DevRequests\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class DevRequestCommentNotification extends BaseNotification
{
    public function __construct(
        public string $commenterName,
        public string $devRequestTitle,
    ) {}

    protected function getNotificationType(): string
    {
        return 'dev_request_comment';
    }

    public function databaseType(object $notifiable): string
    {
        return 'dev_request_comment';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'commenter_name' => $this->commenterName,
            'dev_request_title' => $this->devRequestTitle,
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New Comment on Dev Request')
            ->body("{$this->commenterName} commented on: {$this->devRequestTitle}")
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('View Dev Requests', 'view_dev_requests')
            ->data([
                'url' => config('app.frontend_url') . '/dev/requests',
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Comment on Dev Request')
            ->greeting('New Comment on Dev Request')
            ->line("{$this->commenterName} commented on: {$this->devRequestTitle}")
            ->action('View Dev Requests', config('app.frontend_url') . '/dev/requests');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line("New Comment on Dev Request")
            ->line("{$this->commenterName} commented on: {$this->devRequestTitle}")
            ->url(config('app.frontend_url') . '/dev/requests', 'View');
    }
}
