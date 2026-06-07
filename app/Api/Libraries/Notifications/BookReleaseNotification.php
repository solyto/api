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
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.book_release_title'),
            'body'  => __('notifications.book_release_body', [
                'author' => $this->author,
                'title'  => $this->title,
            ]),
            'link'  => '/libraries/books?releases',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.book_release_title'))
                ->body(__('notifications.book_release_body', [
                    'author' => $this->author,
                    'title'  => $this->title,
                ]))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/libraries/books?releases'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.book_release_title'))
                ->greeting(__('notifications.book_release_title'))
                ->line(__('notifications.book_release_body', [
                    'author' => $this->author,
                    'title'  => $this->title,
                ]))
                ->action(__('notifications.action_view_releases'), config('app.frontend_url') . '/libraries/books?releases')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.book_release_title'))
                ->line(__('notifications.book_release_body', [
                    'author' => $this->author,
                    'title'  => $this->title,
                ]))
                ->url(config('app.frontend_url') . '/libraries/books?releases', __('notifications.action_view'))
        );
    }
}
