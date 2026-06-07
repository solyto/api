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
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.daily_check_in_reminder_title'),
            'body'  => __('notifications.daily_check_in_reminder_body'),
            'link'  => "/check-in/date/{$this->date}",
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.daily_check_in_reminder_title'))
                ->body(__('notifications.daily_check_in_reminder_body'))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . "/check-in/date/{$this->date}"])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.daily_check_in_reminder_title'))
                ->greeting(__('notifications.greeting_evening'))
                ->line(__('notifications.daily_check_in_reminder_body'))
                ->action(__('notifications.action_check_in'), config('app.frontend_url') . "/check-in/date/{$this->date}")
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.daily_check_in_reminder_title'))
                ->line(__('notifications.daily_check_in_reminder_body'))
                ->url(config('app.frontend_url') . "/check-in/date/{$this->date}", __('notifications.action_check_in'))
        );
    }
}
