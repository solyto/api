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
     * @param Collection<int, Goal> $goals
     * @return array<int, array{current: float|null, met: bool, period_start: string, period_end: string, history: array<int, array{period_start: string, met: bool}>}>
     */
    public function progress(User $user, Collection $goals, ?CarbonImmutable $today = null): array
    {
        $today ??= $user->today();
        $weekStart = $user->settings?->week_start === 'sunday' ? CarbonImmutable::SUNDAY : CarbonImmutable::MONDAY;

        $periods = $goals->mapWithKeys(fn (Goal $goal) => [$goal->id => $this->periods($goal->period, $today, $weekStart)]);
        $earliest = $periods->flatten(1)->min(fn (array $period) => $period['start']->toDateString());
        $values = $this->values($goals, $earliest, $today);

        $result = [];
        foreach ($goals as $goal) {
            $current = $periods[$goal->id][0];
            $previous = array_slice($periods[$goal->id], 1);
            $goalValues = $values[$goal->check_in_tracker_id] ?? [];
            $measured = $this->measure($goal, $goalValues, $current['start'], $today);

            $result[$goal->id] = [
                'current' => $measured,
                'met' => $this->isMet($goal, $measured),
                'period_start' => $current['start']->toDateString(),
                'period_end' => $current['end']->toDateString(),
                'history' => array_map(fn (array $period) => [
                    'period_start' => $period['start']->toDateString(),
                    'met' => $this->isMet($goal, $this->measure($goal, $goalValues, $period['start'], $period['end'])),
                ], $previous),
            ];
        }

        return $result;
    }

    /** @param array<string, int> $values */
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

    /** @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}> */
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
            $periods[] = ['start' => $periodStart, 'end' => $periodEnd];
        }

        return $periods;
    }

    /**
     * @param Collection<int, Goal> $goals
     * @return array<int, array<string, int>>
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
