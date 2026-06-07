<?php

namespace App\Api\Users\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class DailyDayReminderNotification extends BaseNotification
{
    public function __construct(
        public string $date,
        public ?Collection $todos = null,
        public ?Collection $events = null
    ) {}

    protected function getNotificationType(): string
    {
        return 'daily_day_reminder';
    }

    public function databaseType(object $notifiable): string
    {
        return 'daily_day_reminder';
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.daily_day_reminder_title'),
            'body'  => $this->buildSummary(),
            'link'  => '/calendar',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.daily_day_reminder_title'))
                ->body($this->buildSummary())
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/calendar'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.daily_day_reminder_title'))
                ->greeting(__('notifications.greeting_morning'))
                ->line($this->buildSummary())
                ->action(__('notifications.action_view_calendar'), config('app.frontend_url') . '/calendar')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.daily_day_reminder_title'))
                ->line($this->buildSummary())
                ->url(config('app.frontend_url') . '/calendar', __('notifications.action_view_calendar'))
        );
    }

    private function buildSummary(): string
    {
        $lines = [];

        if ($this->todos && $this->todos->count() > 0) {
            $lines[] = __('notifications.daily_day_reminder_tasks', ['count' => $this->todos->count()]);
            foreach ($this->todos as $todo) {
                $lines[] = "• {$todo->title}";
            }
        }

        if ($this->events && $this->events->count() > 0) {
            if (!empty($lines)) {
                $lines[] = '';
            }
            $lines[] = __('notifications.daily_day_reminder_events', ['count' => $this->events->count()]);
            foreach ($this->events as $event) {
                $lines[] = "• {$event->title}";
            }
        }

        if (empty($lines)) {
            return __('notifications.daily_day_reminder_clear');
        }

        return implode("\n", $lines);
    }
}
