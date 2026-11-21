<?php

namespace App\Api\Alerts\Notifications;

use App\Api\Alerts\Enums\AlertRule;
use App\Shared\Notifications\BaseNotification;
use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class AlertNotification extends BaseNotification
{
    public function __construct(
        public AlertRule $rule,
        public ?string $trackerKey,
        public ?string $trackerName,
        public ?int $days = null,
        public ?float $threshold = null,
        public ?float $average = null,
    ) {}

    protected function getNotificationType(): string
    {
        return 'alert';
    }

    public function databaseType(object $notifiable): string
    {
        return 'alert';
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->withLocale($notifiable, fn () => [
            'title' => $this->title(),
            'body'  => $this->body(),
            'link'  => $this->link(),
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title($this->title())
                ->body($this->body())
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . $this->link()])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new MailMessage)
                ->subject($this->title())
                ->line($this->body())
                ->action(__('notifications.action_open_app'), config('app.frontend_url') . $this->link())
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line($this->title())
                ->line($this->body())
                ->url(config('app.frontend_url') . $this->link(), __('notifications.action_open_app'))
        );
    }

    private function title(): string
    {
        return $this->rule === AlertRule::GoalReached ? __('notifications.goal_reached_title') : __('notifications.alert_title');
    }

    private function body(): string
    {
        return __('notifications.alert_' . $this->rule->value, [
            'tracker' => $this->trackerKey !== null ? __('widgets.tracker_' . $this->trackerKey) : $this->trackerName,
            'days' => $this->days,
            'threshold' => $this->formatNumber($this->threshold),
            'average' => $this->formatNumber($this->average),
        ]);
    }

    private function link(): string
    {
        return $this->rule === AlertRule::GoalReached ? '/check-in/goals' : '/check-in/trends';
    }

    private function formatNumber(?float $number): string
    {
        return $number === null ? '' : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
