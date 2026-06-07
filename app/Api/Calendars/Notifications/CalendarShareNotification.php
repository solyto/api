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
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.calendar_share_title'),
            'body'  => __('notifications.calendar_share_body', [
                'sender'   => $this->senderName,
                'calendar' => $this->calendarName,
            ]),
            'link'  => '/calendars',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.calendar_share_title'))
                ->body(__('notifications.calendar_share_body', [
                    'sender'   => $this->senderName,
                    'calendar' => $this->calendarName,
                ]))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/calendars'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.calendar_share_title'))
                ->greeting(__('notifications.calendar_share_title'))
                ->line(__('notifications.calendar_share_body', [
                    'sender'   => $this->senderName,
                    'calendar' => $this->calendarName,
                ]))
                ->action(__('notifications.action_view_calendars'), config('app.frontend_url') . '/calendars')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.calendar_share_title'))
                ->line(__('notifications.calendar_share_body', [
                    'sender'   => $this->senderName,
                    'calendar' => $this->calendarName,
                ]))
                ->url(config('app.frontend_url') . '/calendars', __('notifications.action_view'))
        );
    }
}
