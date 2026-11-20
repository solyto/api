<?php

namespace App\Api\Alerts\Services;

use App\Api\Alerts\Models\Alert;
use App\Api\Alerts\Notifications\AlertNotification;
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

        $sent = 0;
        Alert::query()
            ->where('enabled', true)
            ->where(function (Builder $query) use ($timezones) {
                $query->whereIn('user_id', UserSettings::query()->select('user_id')->whereIn('timezone', $timezones));

                // Users without settings or a timezone count as UTC, as everywhere else.
                if (in_array('UTC', $timezones, true)) {
                    $query->orWhereIn('user_id', UserSettings::query()->select('user_id')->whereNull('timezone'))
                        ->orWhereNotIn('user_id', UserSettings::query()->select('user_id'));
                }
            })
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

            // No repeats: notify when the rule starts to hold, then stay quiet until it stops holding.
            if ($active === $alert->is_triggered) {
                continue;
            }

            if (! $active) {
                $alert->update(['is_triggered' => false]);

                continue;
            }

            $alert->update(['is_triggered' => true, 'last_triggered_on' => $todayByUser[$alert->user_id]->toDateString()]);

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
