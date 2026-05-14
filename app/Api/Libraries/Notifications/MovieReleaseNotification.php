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
        return [
            'title' => $this->title,
            'type' => $this->type,
            'release_date' => $this->releaseDate,
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $label = $this->type === 'tv' ? 'New Series Release' : 'New Movie Release';

        return (new WebPushMessage)
            ->title($label)
            ->body("{$this->title} is releasing on {$this->releaseDate}.")
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('View Releases', 'view_releases')
            ->data([
                'url' => config('app.frontend_url') . '/libraries/movies?releases',
                'title' => $this->title,
                'release_date' => $this->releaseDate,
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->type === 'tv' ? 'New Series Release' : 'New Movie Release';

        return (new MailMessage)
            ->subject($label)
            ->greeting($label)
            ->line("{$this->title} is releasing on {$this->releaseDate}!")
            ->action('View Releases', config('app.frontend_url') . '/libraries/movies?releases');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $label = $this->type === 'tv' ? 'New Series Release' : 'New Movie Release';

        return TelegramMessage::create()
            ->line($label)
            ->line("{$this->title} is releasing on {$this->releaseDate}!")
            ->url(config('app.frontend_url') . '/libraries/movies?releases', 'View');
    }
}
