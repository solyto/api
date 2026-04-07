<?php

namespace App\Api\Libraries\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class BookReleaseNotification extends BaseNotification
{
    public function __construct(
        public string $author,
        public string $title,
        public ?int $bookId = null
    ) {}

    protected function getNotificationType(): string
    {
        return 'book_release';
    }

    public function databaseType(object $notifiable): string
    {
        return 'book_release';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'author' => $this->author,
            'title' => $this->title
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New Book Release')
            ->body("There is a new release of {$this->title} by {$this->author}.")
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('View Book', 'view_book')
            ->data([
                'url' => config('app.frontend_url') . '/libraries/books?releases',
                'author' => $this->author,
                'title' => $this->title
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Book Release')
            ->greeting('New Book Release')
            ->line("{$this->title} by {$this->author} is now available!")
            ->action('View Releases', config('app.frontend_url') . '/libraries/books?releases');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line("New Book Release")
            ->line("{$this->title} by {$this->author} is now available!")
            ->url(config('app.frontend_url') . '/libraries/books?releases', 'View');
    }
}
