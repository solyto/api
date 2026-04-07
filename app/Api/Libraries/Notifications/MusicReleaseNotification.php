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
        return [
            'artist' => $this->artist,
            'title' => $this->title
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New Music Release')
            ->body("There is a new release of {$this->title} by {$this->artist}.")
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('View Music', 'view_music')
            ->data([
                'url' => config('app.frontend_url') . '/libraries/music?releases',
                'author' => $this->artist,
                'title' => $this->title
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Music Release')
            ->greeting('New Music Release')
            ->line("{$this->title} by {$this->artist} is now available!")
            ->action('View Releases', config('app.frontend_url') . '/libraries/music?releases');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line("New Music Release")
            ->line("{$this->title} by {$this->artist} is now available!")
            ->url(config('app.frontend_url') . '/libraries/music?releases', 'View');
    }
}
