<?php

namespace App\Api\Alerts\Services;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Goals\Enums\GoalComparison;
use App\Api\Goals\Services\GoalProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AlertEvaluator
{
    public const int MAX_DAYS = 90;

    public function __construct(private readonly GoalProgressService $goalProgress) {}

    /**
     * @param Collection<int, Alert> $alerts
     * @param array<string, CarbonImmutable> $todayByUser user id => local date
     * @return array<int, array{active: bool, average: float|null, streak: int|null, last_entry: string|null, period_start: string|null, period_finished: bool}>
     */
    public function evaluate(Collection $alerts, array $todayByUser): array
    {
        $values = $this->trackerValues($alerts, $todayByUser);
        $goals = $this->goalsMet($alerts, $todayByUser);

        $states = [];
        foreach ($alerts as $alert) {
            $states[$alert->id] = $alert->rule === AlertRule::GoalReached
                ? $this->goalState($goals[$alert->goal_id] ?? null)
                : $this->trackerState($alert, $values[$alert->check_in_tracker_id] ?? [], $todayByUser[$alert->user_id]);
        }

        return $states;
    }

    /** @param array{met: bool, period_start: string, period_finished: bool}|null $goal */
    private function goalState(?array $goal): array
    {
        return [
            'active' => $goal['met'] ?? false,
            'average' => null,
            'streak' => null,
            'last_entry' => null,
            'period_start' => $goal['period_start'] ?? null,
            'period_finished' => $goal['period_finished'] ?? false,
        ];
    }

    /** @param array<string, int> $values date => value */
    private function trackerState(Alert $alert, array $values, CarbonImmutable $today): array
    {
        // Completed days only: the window ends the day before $today.
        $window = [];
        for ($i = 1; $i <= $alert->days; $i++) {
            $window[] = $today->subDays($i)->toDateString();
        }

        $entries = array_values(array_filter($window, fn (string $date) => isset($values[$date])));
        $lastEntry = collect(array_keys($values))->filter(fn (string $date) => $date < $today->toDateString())->max();
        $streak = $this->streak($values, $today);

        // "Nothing logged" and count averages treat days without an entry as empty,
        // so they wait until the tracker has existed for the whole window.
        $trackerCreatedOn = $alert->checkInTracker->created_at->setTimezone($today->getTimezone())->toDateString();
        $existedForWindow = $trackerCreatedOn <= $today->subDays($alert->days)->toDateString();

        if ($alert->rule->isAverage()) {
            $average = $this->average($alert, $values, $window);
            $countsEmptyDays = $alert->checkInTracker->type === CheckInTrackerType::Count;
            $canJudge = $average !== null && ($existedForWindow || ! $countsEmptyDays);
            $crossesThreshold = $alert->rule === AlertRule::AverageBelow
                ? $average < $alert->threshold
                : $average > $alert->threshold;

            return [
                'active' => $canJudge && $crossesThreshold,
                'average' => $average,
                'streak' => null,
                'last_entry' => $lastEntry,
                'period_start' => null,
                'period_finished' => false,
            ];
        }

        $active = $alert->rule === AlertRule::NoEntryDays
            ? $existedForWindow && $entries === []
            : count($entries) === count($window);

        return [
            'active' => $active,
            'average' => null,
            'streak' => $streak,
            'last_entry' => $lastEntry,
            'period_start' => null,
            'period_finished' => false,
        ];
    }

    /**
     * @param array<string, int> $values
     * @param array<int, string> $window
     */
    private function average(Alert $alert, array $values, array $window): ?float
    {
        $inWindow = array_map(fn (string $date) => $values[$date] ?? null, $window);

        // Counts average per day, a day without an entry as 0: "under 5 glasses a day on average".
        if ($alert->checkInTracker->type === CheckInTrackerType::Count) {
            return round(array_sum(array_filter($inWindow)) / count($window), 2);
        }

        $rated = array_filter($inWindow, fn (?int $value) => $value !== null && $value > 0);

        return $rated === [] ? null : round(array_sum($rated) / count($rated), 2);
    }

    /** @param array<string, int> $values */
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
     * @param Collection<int, Alert> $alerts
     * @param array<string, CarbonImmutable> $todayByUser
     * @return array<int, array{met: bool, period_start: string, period_finished: bool}> goal id => state
     */
    private function goalsMet(Collection $alerts, array $todayByUser): array
    {
        $met = [];
        $alerts->where('rule', AlertRule::GoalReached)
            ->groupBy('user_id')
            ->each(function (Collection $userAlerts, string $userId) use ($todayByUser, &$met) {
                $user = $userAlerts->first()->user;
                $goals = $userAlerts->pluck('goal')->filter()->keyBy('id');
                $yesterday = $todayByUser[$userId]->subDay();
                $progress = $this->goalProgress->progress($user, $goals->values(), $yesterday);

                foreach ($progress as $goalId => $state) {
                    $periodFinished = $state['period_end'] === $yesterday->toDateString();
                    $atMost = $goals[$goalId]->comparison === GoalComparison::AtMost;
                    // An at-most goal can still be missed until its period is over.
                    $decidedYet = $periodFinished || ! $atMost;

                    $met[$goalId] = [
                        'met' => $state['met'] && $decidedYet,
                        'period_start' => $state['period_start'],
                        'period_finished' => $periodFinished,
                    ];
                }
            });

        return $met;
    }
}
