<?php

namespace App\Shared\Notifications;

use App\Shared\Notifications\Channels\TelegramChannel;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TestNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail', WebPushChannel::class];

        $connection = $notifiable->telegramConnection;
        if ($connection && $connection->is_confirmed) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function databaseType(object $notifiable): string
    {
        return 'test';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Test Notification',
            'body'  => 'This is a test notification to verify all channels are working.',
            'link'  => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Test Notification')
            ->greeting('Test Notification')
            ->line('This is a test notification to verify all channels are working.')
            ->action('Open Solyto', config('app.frontend_url'));
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Test Notification')
            ->body('This is a test notification to verify all channels are working.')
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->data(['url' => config('app.frontend_url')]);
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line('Test Notification')
            ->line('This is a test notification to verify all channels are working.')
            ->url(config('app.frontend_url'), 'Open Solyto');
    }
}
