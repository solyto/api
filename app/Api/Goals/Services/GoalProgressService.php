<?php

namespace App\Api\Goals\Services;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInValue;
use App\Api\Goals\Enums\GoalPeriod;
use App\Api\Goals\Models\Goal;
use App\Api\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GoalProgressService
{
    public const int HISTORY_PERIODS = 6;

    /**
     * Progress of the current period (up to and including $today) and whether each of the
     * previous periods was met. One values query for all goals.
     *
     * @param Collection<int, Goal> $goals with checkInTracker loaded
     * @return array<int, array{current: float|null, met: bool, period_start: string, period_end: string, history: array<int, array{period_start: string, met: bool}>}>
     */
    public function progress(User $user, Collection $goals, ?CarbonImmutable $today = null): array
    {
        $today ??= $this->today($user);
        $weekStart = $user->settings?->week_start === 'sunday' ? CarbonImmutable::SUNDAY : CarbonImmutable::MONDAY;

        $periods = $goals->mapWithKeys(fn (Goal $goal) => [$goal->id => $this->periods($goal->period, $today, $weekStart)]);
        $earliest = $periods->flatten(1)->min(fn (array $period) => $period[0]->toDateString());
        $values = $this->values($goals, $earliest, $today);

        $result = [];
        foreach ($goals as $goal) {
            $current = $periods[$goal->id][0];
            $previous = array_slice($periods[$goal->id], 1);
            $measured = $this->measure($goal, $values[$goal->check_in_tracker_id] ?? [], $current[0], $today);

            $result[$goal->id] = [
                'current' => $measured,
                'met' => $this->isMet($goal, $measured),
                'period_start' => $current[0]->toDateString(),
                'period_end' => $current[1]->toDateString(),
                'history' => array_map(fn (array $period) => [
                    'period_start' => $period[0]->toDateString(),
                    'met' => $this->isMet($goal, $this->measure($goal, $values[$goal->check_in_tracker_id] ?? [], $period[0], $period[1])),
                ], $previous),
            ];
        }

        return $result;
    }

    public function today(User $user): CarbonImmutable
    {
        return CarbonImmutable::now($user->settings->timezone ?? 'UTC')->startOfDay();
    }

    /**
     * What a goal counts: days with an entry for yes/no and sports, the total for counts, the average for 1–5.
     *
     * @param array<string, int> $values date => value
     */
    private function measure(Goal $goal, array $values, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $inPeriod = array_filter(
            $values,
            fn (string $date) => $date >= $from->toDateString() && $date <= $to->toDateString(),
            ARRAY_FILTER_USE_KEY
        );

        return match ($goal->checkInTracker->type) {
            CheckInTrackerType::YesNo, CheckInTrackerType::Sport => (float) count($inPeriod),
            CheckInTrackerType::Count => (float) array_sum($inPeriod),
            CheckInTrackerType::Scale => $this->average($inPeriod),
        };
    }

    /** @param array<string, int> $values */
    private function average(array $values): ?float
    {
        $rated = array_filter($values, fn (int $value) => $value > 0);

        return $rated === [] ? null : round(array_sum($rated) / count($rated), 2);
    }

    private function isMet(Goal $goal, ?float $measured): bool
    {
        return $measured !== null && $goal->comparison->isMet($measured, $goal->target);
    }

    /**
     * The current period first, then the previous ones, newest first; each as [start, end].
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function periods(GoalPeriod $period, CarbonImmutable $today, int $weekStart): array
    {
        $start = match ($period) {
            GoalPeriod::Day => $today,
            GoalPeriod::Week => $today->startOfWeek($weekStart),
            GoalPeriod::Month => $today->startOfMonth(),
        };

        $periods = [];
        for ($i = 0; $i <= self::HISTORY_PERIODS; $i++) {
            $periodStart = match ($period) {
                GoalPeriod::Day => $start->subDays($i),
                GoalPeriod::Week => $start->subWeeks($i),
                GoalPeriod::Month => $start->subMonthsNoOverflow($i),
            };
            $periodEnd = match ($period) {
                GoalPeriod::Day => $periodStart,
                GoalPeriod::Week => $periodStart->addDays(6),
                GoalPeriod::Month => $periodStart->endOfMonth()->startOfDay(),
            };
            $periods[] = [$periodStart, $periodEnd];
        }

        return $periods;
    }

    /**
     * @param Collection<int, Goal> $goals
     * @return array<int, array<string, int>> tracker id => date => value
     */
    private function values(Collection $goals, ?string $from, CarbonImmutable $to): array
    {
        $trackerIds = $goals->pluck('check_in_tracker_id')->filter()->unique()->values();

        if ($trackerIds->isEmpty() || $from === null) {
            return [];
        }

        $values = [];
        CheckInValue::whereIn('check_in_tracker_id', $trackerIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['check_in_tracker_id', 'date', 'value'])
            ->each(function (CheckInValue $value) use (&$values) {
                $values[$value->check_in_tracker_id][$value->date->format('Y-m-d')] = $value->value;
            });

        return $values;
    }
}
