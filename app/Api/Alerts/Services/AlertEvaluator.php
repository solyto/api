<?php

namespace App\Api\Alerts\Services;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Goals\Services\GoalProgressService;
use App\Api\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AlertEvaluator
{
    public const int MAX_DAYS = 90;

    public function __construct(private readonly GoalProgressService $goalProgress) {}

    /**
     * Whether each alert's rule holds, judged on completed days only (up to and including the day
     * before $today, the user's local date), plus what the rule currently sees.
     * One values query for all tracker alerts; goal alerts are grouped per user.
     *
     * @param Collection<int, Alert> $alerts with checkInTracker, goal.checkInTracker and user.settings loaded
     * @param array<string, CarbonImmutable> $todayByUser user id => local date
     * @return array<int, array{active: bool, average: float|null, streak: int|null, last_entry: string|null}>
     */
    public function evaluate(Collection $alerts, array $todayByUser): array
    {
        $values = $this->trackerValues($alerts, $todayByUser);
        $goalsMet = $this->goalsMet($alerts, $todayByUser);

        $states = [];
        foreach ($alerts as $alert) {
            $today = $todayByUser[$alert->user_id];

            $states[$alert->id] = $alert->rule === AlertRule::GoalReached
                ? ['active' => $goalsMet[$alert->goal_id] ?? false, 'average' => null, 'streak' => null, 'last_entry' => null]
                : $this->trackerState($alert, $values[$alert->check_in_tracker_id] ?? [], $today);
        }

        return $states;
    }

    /**
     * @param array<string, int> $values date => value
     * @return array{active: bool, average: float|null, streak: int|null, last_entry: string|null}
     */
    private function trackerState(Alert $alert, array $values, CarbonImmutable $today): array
    {
        $window = [];
        for ($i = 1; $i <= $alert->days; $i++) {
            $window[] = $today->subDays($i)->toDateString();
        }

        $entries = array_values(array_filter($window, fn (string $date) => isset($values[$date])));
        $lastEntry = collect(array_keys($values))->filter(fn (string $date) => $date < $today->toDateString())->max();
        $streak = $this->streak($values, $today);

        if ($alert->rule->isAverage()) {
            $average = $this->average($alert, $values, $window);
            $active = $average !== null && ($alert->rule === AlertRule::AverageBelow
                ? $average < $alert->threshold
                : $average > $alert->threshold);

            return ['active' => $active, 'average' => $average, 'streak' => null, 'last_entry' => $lastEntry];
        }

        return [
            'active' => $alert->rule === AlertRule::NoEntryDays ? $entries === [] : count($entries) === count($window),
            'average' => null,
            'streak' => $streak,
            'last_entry' => $lastEntry,
        ];
    }

    /**
     * 1–5: the average of the ratings given (no ratings, no average). Counts: per day, with days
     * without an entry counting as 0 — "under 5 glasses a day on average".
     *
     * @param array<string, int> $values
     * @param array<int, string> $window
     */
    private function average(Alert $alert, array $values, array $window): ?float
    {
        $inWindow = array_map(fn (string $date) => $values[$date] ?? null, $window);

        if ($alert->checkInTracker->type === CheckInTrackerType::Count) {
            return round(array_sum(array_filter($inWindow)) / count($window), 2);
        }

        $rated = array_filter($inWindow, fn (?int $value) => $value !== null && $value > 0);

        return $rated === [] ? null : round(array_sum($rated) / count($rated), 2);
    }

    /** Days in a row with an entry, ending yesterday. @param array<string, int> $values */
    private function streak(array $values, CarbonImmutable $today): int
    {
        $streak = 0;
        while ($streak < self::MAX_DAYS && isset($values[$today->subDays($streak + 1)->toDateString()])) {
            $streak++;
        }

        return $streak;
    }

    /**
     * @param Collection<int, Alert> $alerts
     * @param array<string, CarbonImmutable> $todayByUser
     * @return array<int, array<string, int>> tracker id => date => value
     */
    private function trackerValues(Collection $alerts, array $todayByUser): array
    {
        $trackerIds = $alerts->pluck('check_in_tracker_id')->filter()->unique()->values();

        if ($trackerIds->isEmpty()) {
            return [];
        }

        $days = collect($todayByUser);
        $values = [];
        CheckInValue::whereIn('check_in_tracker_id', $trackerIds)
            ->whereDate('date', '>=', $days->min()->subDays(self::MAX_DAYS)->toDateString())
            ->whereDate('date', '<', $days->max()->toDateString())
            ->get(['check_in_tracker_id', 'date', 'value'])
            ->each(function (CheckInValue $value) use (&$values) {
                $values[$value->check_in_tracker_id][$value->date->format('Y-m-d')] = $value->value;
            });

        return $values;
    }

    /**
     * Whether each goal's current period is met, counting completed days only.
     *
     * @param Collection<int, Alert> $alerts
     * @param array<string, CarbonImmutable> $todayByUser
     * @return array<int, bool> goal id => met
     */
    private function goalsMet(Collection $alerts, array $todayByUser): array
    {
        $met = [];
        $alerts->where('rule', AlertRule::GoalReached)
            ->groupBy('user_id')
            ->each(function (Collection $userAlerts, string $userId) use ($todayByUser, &$met) {
                /** @var User $user */
                $user = $userAlerts->first()->user;
                $goals = $userAlerts->pluck('goal')->filter();
                $progress = $this->goalProgress->progress($user, $goals, $todayByUser[$userId]->subDay());

                foreach ($progress as $goalId => $goal) {
                    $met[$goalId] = $goal['met'];
                }
            });

        return $met;
    }
}
