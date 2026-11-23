<?php

namespace App\Api\Alerts\Services;

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
        $sent = 0;
        $query->where('enabled', true)
            ->with(['user.settings', 'user.notificationSettings', 'checkInTracker', 'goal.checkInTracker'])
            ->chunkById(self::CHUNK, function (Collection $alerts) use ($now, &$sent) {
                $sent += $this->dispatch($alerts, $now);
            });

        return $sent;
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
            $active = $states[$alert->id]['active'];
            $today = $todayByUser[$alert->user_id]->toDateString();

            // No repeats: notify when the rule starts to hold, then stay quiet until it stops holding.
            // Rules judged per finished period (at-most goals) notify for each period, but once a day at most.
            $newPeriod = $active
                && $states[$alert->id]['every_period']
                && $alert->last_triggered_on?->toDateString() !== $today;

            if ($active === $alert->is_triggered && ! $newPeriod) {
                continue;
            }

            if (! $active) {
                $alert->update(['is_triggered' => false]);

                continue;
            }

            $alert->update(['is_triggered' => true, 'last_triggered_on' => $today]);

            // One user's failure (a mail or Telegram outage) must not cost everyone after them their alerts.
            try {
                $alert->user->notify($this->notification($alert, $states[$alert->id]));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
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
