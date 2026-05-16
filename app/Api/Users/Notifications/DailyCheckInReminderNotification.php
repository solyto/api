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

    private function resolveLocale(object $notifiable): string
    {
        $lang = $notifiable->settings?->language ?? 'en';
        return in_array($lang, ['en', 'de', 'fr', 'es']) ? $lang : 'en';
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $previous = app()->getLocale();
        app()->setLocale($this->resolveLocale($notifiable));

        $message = (new WebPushMessage)
            ->title(__('bot.check_in_reminder_title'))
            ->body(__('bot.check_in_reminder_body'))
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action(__('bot.check_in_action'), 'check_in')
            ->data([
                'url' => config('app.frontend_url') . "/check-in/date/{$this->date}",
            ]);

        app()->setLocale($previous);
        return $message;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $previous = app()->getLocale();
        app()->setLocale($this->resolveLocale($notifiable));

        $message = (new MailMessage)
            ->subject(__('bot.check_in_reminder_title'))
            ->greeting('Good Evening!')
            ->line(__('bot.check_in_reminder_body'))
            ->action(__('bot.check_in_action'), config('app.frontend_url') . "/check-in/date/{$this->date}");

        app()->setLocale($previous);
        return $message;
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $previous = app()->getLocale();
        app()->setLocale($this->resolveLocale($notifiable));

        $message = TelegramMessage::create()
            ->line(__('bot.check_in_reminder_title'))
            ->line(__('bot.check_in_reminder_body'))
            ->url(config('app.frontend_url') . "/check-in/date/{$this->date}", __('bot.check_in_action'));

        app()->setLocale($previous);
        return $message;
    }
}
