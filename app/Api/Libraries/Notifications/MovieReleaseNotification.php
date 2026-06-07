<?php

namespace App\Api\Libraries\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class MovieReleaseNotification extends BaseNotification
{
    public function __construct(
        public string $title,
        public string $type,
        public string $releaseDate,
    ) {}

    protected function getNotificationType(): string
    {
        return 'movie_release';
    }

    public function databaseType(object $notifiable): string
    {
        return 'movie_release';
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.screen_release_title'),
            'body'  => __('notifications.screen_release_body', [
                'title' => $this->title,
                'date'  => $this->releaseDate,
            ]),
            'link'  => '/libraries/movies?releases',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.screen_release_title'))
                ->body(__('notifications.screen_release_body', [
                    'title' => $this->title,
                    'date'  => $this->releaseDate,
                ]))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/libraries/movies?releases'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.screen_release_title'))
                ->greeting(__('notifications.screen_release_title'))
                ->line(__('notifications.screen_release_body', [
                    'title' => $this->title,
                    'date'  => $this->releaseDate,
                ]))
                ->action(__('notifications.action_view_releases'), config('app.frontend_url') . '/libraries/movies?releases')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.screen_release_title'))
                ->line(__('notifications.screen_release_body', [
                    'title' => $this->title,
                    'date'  => $this->releaseDate,
                ]))
                ->url(config('app.frontend_url') . '/libraries/movies?releases', __('notifications.action_view'))
        );
    }
}
