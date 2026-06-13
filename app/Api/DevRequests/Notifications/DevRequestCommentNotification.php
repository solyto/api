<?php

namespace App\Api\DevRequests\Notifications;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\Users\Models\User;
use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class DevRequestCommentNotification extends BaseNotification
{
    public function __construct(
        private readonly DevRequest $devRequest,
        private readonly User $commenter,
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
                'commenter' => $this->commenter->name,
                'title'     => $this->devRequest->title,
            ]),
            'link'  => $this->getUrl(false),
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.dev_request_comment_title'))
                ->body(__('notifications.dev_request_comment_body', [
                    'commenter' => $this->commenter->name,
                    'title'     => $this->devRequest->title,
                ]))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => $this->getUrl(true)])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.dev_request_comment_title'))
                ->greeting(__('notifications.dev_request_comment_title'))
                ->line(__('notifications.dev_request_comment_body', [
                    'commenter' => $this->commenter->name,
                    'title'     => $this->devRequest->title,
                ]))
                ->action(__('notifications.action_view_dev_requests'), $this->getUrl(true))
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.dev_request_comment_title'))
                ->line(__('notifications.dev_request_comment_body', [
                    'commenter' => $this->commenter->name,
                    'title'     => $this->devRequest->title,
                ]))
                ->url($this->getUrl(true), __('notifications.action_view'))
        );
    }

    private function getUrl(bool $withFrontendBaseUrl = false): string
    {
        return ($withFrontendBaseUrl ? config('app.frontend_url') : '') . '/dev-requests/' . $this->devRequest->id;
    }
}
