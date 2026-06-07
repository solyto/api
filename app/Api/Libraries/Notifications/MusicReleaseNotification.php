<?php

namespace App\Api\Libraries\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class MusicReleaseNotification extends BaseNotification
{
    public function __construct(
        public string $artist,
        public string $title
    ) {}

    protected function getNotificationType(): string
    {
        return 'music_release';
    }

    public function databaseType(object $notifiable): string
    {
        return 'music_release';
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.music_release_title'),
            'body'  => __('notifications.music_release_body', [
                'artist' => $this->artist,
                'title'  => $this->title,
            ]),
            'link'  => '/libraries/music?releases',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.music_release_title'))
                ->body(__('notifications.music_release_body', [
                    'artist' => $this->artist,
                    'title'  => $this->title,
                ]))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/libraries/music?releases'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.music_release_title'))
                ->greeting(__('notifications.music_release_title'))
                ->line(__('notifications.music_release_body', [
                    'artist' => $this->artist,
                    'title'  => $this->title,
                ]))
                ->action(__('notifications.action_view_releases'), config('app.frontend_url') . '/libraries/music?releases')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.music_release_title'))
                ->line(__('notifications.music_release_body', [
                    'artist' => $this->artist,
                    'title'  => $this->title,
                ]))
                ->url(config('app.frontend_url') . '/libraries/music?releases', __('notifications.action_view'))
        );
    }
}
