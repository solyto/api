<?php

namespace App\Api\Calendars\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class CalendarShareNotification extends BaseNotification
{
    public function __construct(
        public string $calendarName,
        public string $senderName,
    ) {}

    protected function getNotificationType(): string
    {
        return 'calendar_share';
    }

    public function databaseType(object $notifiable): string
    {
        return 'calendar_share';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'calendar_name' => $this->calendarName,
            'sender_name' => $this->senderName,
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Calendar Shared')
            ->body("{$this->senderName} shared the calendar '{$this->calendarName}' with you.")
            ->icon(config('app.landing_page_url') . '/logo_cut.png')
            ->action('View Calendars', 'view_calendars')
            ->data([
                'url' => config('app.frontend_url') . '/calendars',
            ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Calendar Shared')
            ->greeting('Calendar Shared')
            ->line("{$this->senderName} shared the calendar '{$this->calendarName}' with you.")
            ->action('View Calendars', config('app.frontend_url') . '/calendars');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->line("Calendar Shared")
            ->line("{$this->senderName} shared the calendar '{$this->calendarName}' with you.")
            ->url(config('app.frontend_url') . '/calendars', 'View');
    }
}
