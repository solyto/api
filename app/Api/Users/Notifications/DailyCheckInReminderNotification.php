<?php

namespace App\Api\Users\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class DailyCheckInReminderNotification extends BaseNotification
{
    public function __construct(
        public string $date
    ) {}

    protected function getNotificationType(): string
    {
        return 'daily_check_in_reminder';
    }

    public function databaseType(object $notifiable): string
    {
        return 'daily_check_in_reminder';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'date' => $this->date
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Daily Check-in Reminder')
            ->body("Have you completed your daily check-in yet?")
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('Check In', 'check_in')
            ->data([
                'url' => config('app.frontend_url') . "/check-in/date/{$this->date}",
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Daily Check-in Reminder')
            ->greeting('Good Evening!')
            ->line('Have you completed your daily check-in yet?')
            ->action('Check In', config('app.frontend_url') . "/check-in/date/{$this->date}");
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line("Daily Check-in Reminder")
            ->line("Have you completed your daily check-in yet?")
            ->url(config('app.frontend_url') . "/check-in/date/{$this->date}", 'Check In');
    }
}
