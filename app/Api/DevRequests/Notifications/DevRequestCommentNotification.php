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
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.dev_request_comment_title'),
            'body'  => __('notifications.dev_request_comment_body', [
                'commenter' => $this->commenterName,
                'title'     => $this->devRequestTitle,
            ]),
            'link'  => '/dev/requests',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.dev_request_comment_title'))
                ->body(__('notifications.dev_request_comment_body', [
                    'commenter' => $this->commenterName,
                    'title'     => $this->devRequestTitle,
                ]))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/dev/requests'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.dev_request_comment_title'))
                ->greeting(__('notifications.dev_request_comment_title'))
                ->line(__('notifications.dev_request_comment_body', [
                    'commenter' => $this->commenterName,
                    'title'     => $this->devRequestTitle,
                ]))
                ->action(__('notifications.action_view_dev_requests'), config('app.frontend_url') . '/dev/requests')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.dev_request_comment_title'))
                ->line(__('notifications.dev_request_comment_body', [
                    'commenter' => $this->commenterName,
                    'title'     => $this->devRequestTitle,
                ]))
                ->url(config('app.frontend_url') . '/dev/requests', __('notifications.action_view'))
        );
    }
}
