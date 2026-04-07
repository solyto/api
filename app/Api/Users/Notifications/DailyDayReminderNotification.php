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
        return [
            'date' => $this->date,
            'todo_count' => $this->todos?->count() ?? 0,
            'event_count' => $this->events?->count() ?? 0
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $body = $this->buildSummary();

        return (new WebPushMessage)
            ->title('Your Day Ahead')
            ->body($body)
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('View Calendar', 'view_calendar')
            ->data([
                'url' => config('app.frontend_url') . '/calendar',
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Day Ahead')
            ->greeting('Good Morning!')
            ->line($this->buildSummary())
            ->action('View Calendar', config('app.frontend_url') . '/calendar');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line("Your Day Ahead")
            ->line($this->buildSummary())
            ->url(config('app.frontend_url') . '/calendar', 'View Calendar');
    }

    private function buildSummary(): string
    {
        $lines = [];

        if ($this->todos && $this->todos->count() > 0) {
            $lines[] = "Tasks (" . $this->todos->count() . "):";
            foreach ($this->todos as $todo) {
                $lines[] = "• {$todo->title}";
            }
        }

        if ($this->events && $this->events->count() > 0) {
            if (!empty($lines)) {
                $lines[] = "";
            }
            $lines[] = "Events (" . $this->events->count() . "):";
            foreach ($this->events as $event) {
                $lines[] = "• {$event->title}";
            }
        }

        if (empty($lines)) {
            return "You have a clear day ahead!";
        }

        return implode("\n", $lines);
    }
}
