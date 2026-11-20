<?php

namespace App\Api\CheckIn\Notifications;

use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class CheckInAlertNotification extends BaseNotification
{
    public function __construct(
        public string $date,
        public ?string $trackerKey,
        public ?string $trackerName,
        public ?int $current = null,
        public ?int $target = null,
    ) {}

    protected function getNotificationType(): string
    {
        return 'check_in_alert';
    }

    public function databaseType(object $notifiable): string
    {
        return 'check_in_alert';
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->withLocale($notifiable, fn () => [
            'title' => __('notifications.check_in_alert_title'),
            'body'  => $this->body(),
            'link'  => "/check-in/date/{$this->date}",
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title(__('notifications.check_in_alert_title'))
                ->body($this->body())
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . "/check-in/date/{$this->date}"])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject(__('notifications.check_in_alert_title'))
                ->line($this->body())
                ->action(__('notifications.action_check_in'), config('app.frontend_url') . "/check-in/date/{$this->date}")
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line(__('notifications.check_in_alert_title'))
                ->line($this->body())
                ->url(config('app.frontend_url') . "/check-in/date/{$this->date}", __('notifications.action_check_in'))
        );
    }

    private function body(): string
    {
        $tracker = $this->trackerKey !== null ? __('widgets.tracker_' . $this->trackerKey) : $this->trackerName;

        return $this->target !== null
            ? __('notifications.check_in_alert_below', ['tracker' => $tracker, 'current' => $this->current ?? 0, 'target' => $this->target])
            : __('notifications.check_in_alert_missing', ['tracker' => $tracker]);
    }
}
