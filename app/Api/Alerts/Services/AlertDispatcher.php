<?php

namespace App\Api\Alerts\Services;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\Alerts\Notifications\AlertNotification;
use App\Api\Users\Models\User;
use App\Api\Users\Models\UserSettings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

// Runs every hour; each run only looks at users whose local time has just reached CHECK_HOUR.
class AlertDispatcher
{
    public const int CHECK_HOUR = 9;

    private const int CHUNK = 500;

    public function __construct(private readonly AlertEvaluator $evaluator) {}

    public function sendDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $timezones = $this->timezonesAtCheckHour($now);

        if ($timezones === []) {
            return 0;
        }

        return $this->send(
            Alert::query()->where(function (Builder $query) use ($timezones) {
                $query->whereIn('user_id', UserSettings::query()->select('user_id')->whereIn('timezone', $timezones));

                // Users without settings or a timezone count as UTC, as everywhere else.
                if (in_array('UTC', $timezones, true)) {
                    $query->orWhereIn('user_id', UserSettings::query()->select('user_id')->whereNull('timezone'))
                        ->orWhereNotIn('user_id', UserSettings::query()->select('user_id'));
                }
            }),
            $now
        );
    }

    // For testing: checks one user's alerts now, whatever their local time.
    public function sendForUser(User $user, ?CarbonImmutable $now = null): int
    {
        return $this->send(Alert::forUser($user->id), $now ?? CarbonImmutable::now());
    }

    /** @param Builder<Alert> $query */
    private function send(Builder $query, CarbonImmutable $now): int
    {
        // A copy: where() changes the query it's called on, and the query is used again below.
        $this->restartSwitchedOffAlerts(clone $query);

        $sent = 0;
        $query->where('enabled', true)
            ->with(['user.settings', 'user.notificationSettings', 'checkInTracker', 'goal.checkInTracker'])
            ->chunkById(self::CHUNK, function (Collection $alerts) use ($now, &$sent) {
                $sent += $this->dispatch($alerts, $now);
            });

        return $sent;
    }

    /**
     * A switched-off alert (or one whose tracker is off) isn't checked, so it can't see its rule stop
     * holding. Forgetting that it notified lets it start fresh once it's switched on again.
     *
     * @param Builder<Alert> $query
     */
    private function restartSwitchedOffAlerts(Builder $query): void
    {
        $query->switchedOff()->where('is_triggered', true)->update(['is_triggered' => false]);
    }

    /** @param Collection<int, Alert> $alerts */
    private function dispatch(Collection $alerts, CarbonImmutable $now): int
    {
        // A switched-off tracker has nothing new to judge, for its own alerts and its goal's.
        $alerts = $alerts->filter(fn (Alert $alert) => ($alert->checkInTracker ?? $alert->goal?->checkInTracker)?->enabled);

        if ($alerts->isEmpty()) {
            return 0;
        }

        $todayByUser = $alerts->pluck('user')->unique('id')->mapWithKeys(fn ($user) => [
            $user->id => $now->setTimezone($user->settings->timezone ?? 'UTC')->startOfDay(),
        ])->all();
        $states = $this->evaluator->evaluate($alerts, $todayByUser);

        $sent = 0;
        foreach ($alerts as $alert) {
            $state = $states[$alert->id];

            if (! $this->shouldNotify($alert, $state)) {
                // is_triggered follows whether the rule holds.
                if ($alert->is_triggered !== $state['active']) {
                    $alert->update(['is_triggered' => $state['active']]);
                }

                continue;
            }

            $alert->update(['is_triggered' => true, 'last_triggered_on' => $todayByUser[$alert->user_id]->toDateString()]);

            // One user's failure (a mail or Telegram outage) must not cost everyone after them their alerts.
            try {
                $alert->user->notify($this->notification($alert, $state));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Goal alerts notify once per period in which the goal is reached. Other alerts notify when
     * their rule starts to hold, then stay quiet until it stops holding.
     *
     * @param array<string, mixed> $state
     */
    private function shouldNotify(Alert $alert, array $state): bool
    {
        if (! $state['active']) {
            return false;
        }

        if ($alert->rule === AlertRule::GoalReached) {
            return ! $this->notifiedForPeriod($alert, $state['period_start']);
        }

        return ! $alert->is_triggered;
    }

    // A notification about a period is sent after the period's first day, so one sent after that day was about this period.
    private function notifiedForPeriod(Alert $alert, string $periodStart): bool
    {
        return $alert->last_triggered_on !== null && $alert->last_triggered_on->toDateString() > $periodStart;
    }

    /** @param array<string, mixed> $state */
    private function notification(Alert $alert, array $state): AlertNotification
    {
        $tracker = $alert->checkInTracker ?? $alert->goal?->checkInTracker;

        return new AlertNotification(
            rule: $alert->rule,
            trackerKey: $tracker?->key,
            trackerName: $tracker?->name,
            days: $alert->days,
            threshold: $alert->threshold,
            average: $state['average'],
            goalComparison: $alert->goal?->comparison,
            goalPeriod: $alert->goal?->period,
            periodFinished: $state['period_finished'],
        );
    }

    /** @return array<int, string> */
    private function timezonesAtCheckHour(CarbonImmutable $now): array
    {
        $timezones = [];
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $timezone) {
            // Some systems list files of the tz database that aren't zones.
            try {
                if ((int) $now->setTimezone($timezone)->format('G') === self::CHECK_HOUR) {
                    $timezones[] = $timezone;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $timezones;
    }
}
