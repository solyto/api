<?php

namespace App\Api\Alerts\Notifications;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Goals\Enums\GoalComparison;
use App\Api\Goals\Enums\GoalPeriod;
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
        public ?GoalComparison $goalComparison = null,
        public ?GoalPeriod $goalPeriod = null,
        public bool $periodFinished = false,
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
        return match (true) {
            $this->rule !== AlertRule::GoalReached => __('notifications.alert_title'),
            $this->goalComparison === GoalComparison::AtMost => __('notifications.goal_kept_title'),
            default => __('notifications.goal_reached_title'),
        };
    }

    private function body(): string
    {
        return __('notifications.' . $this->bodyKey(), [
            'tracker' => $this->trackerKey !== null ? __('widgets.tracker_' . $this->trackerKey) : $this->trackerName,
            'days' => $this->days,
            'threshold' => $this->formatNumber($this->threshold),
            'average' => $this->formatNumber($this->average),
        ]);
    }

    // Goals say which period they were reached in: "this week" while it runs, "last week" once it ended.
    private function bodyKey(): string
    {
        if ($this->rule !== AlertRule::GoalReached) {
            return 'alert_' . $this->rule->value;
        }

        $kept = $this->goalComparison === GoalComparison::AtMost;
        // An at-most goal is only reached once its period is over, so it always looks back.
        $lookingBack = $kept || $this->periodFinished;
        $when = match ($this->goalPeriod ?? GoalPeriod::Week) {
            GoalPeriod::Day => 'yesterday',
            GoalPeriod::Week => $lookingBack ? 'last_week' : 'this_week',
            GoalPeriod::Month => $lookingBack ? 'last_month' : 'this_month',
        };

        return 'alert_goal_'.($kept ? 'kept' : 'reached').'_'.$when;
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
