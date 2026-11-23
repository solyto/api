<?php

namespace App\Api\Goals\Services;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Enums\AlertSource;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Goals\Enums\GoalPeriod;
use App\Api\Goals\Enums\GoalSource;
use App\Api\Goals\Models\Goal;
use App\Api\Users\Models\User;
use Illuminate\Support\Collection;
use App\Shared\Exceptions\LimitReachedException;
use Illuminate\Validation\ValidationException;

class GoalService
{
    public const int MAX_GOALS = 50;

    /** @return Collection<int, Goal> */
    public function list(User $user): Collection
    {
        return Goal::forUser($user->id)
            ->with(['checkInTracker', 'reachedAlert'])
            ->orderBy('id')
            ->get();
    }

    public function create(User $user, array $data): Goal
    {
        if (Goal::forUser($user->id)->count() >= self::MAX_GOALS) {
            throw new LimitReachedException('You have reached the maximum number of goals.');
        }

        $tracker = CheckInTracker::forUser($user->id)->findOrFail($data['check_in_tracker_id']);

        // One goal per tracker for now; the daily page shows one progress line.
        if (Goal::where('check_in_tracker_id', $tracker->id)->exists()) {
            throw ValidationException::withMessages(['check_in_tracker_id' => 'This tracker already has a goal.']);
        }
        $this->validateTarget($tracker, GoalPeriod::from($data['period']), (float) $data['target']);

        $goal = Goal::create([
            'user_id' => $user->id,
            'source' => GoalSource::CheckInTracker,
            'check_in_tracker_id' => $tracker->id,
            'period' => $data['period'],
            'comparison' => $data['comparison'],
            'target' => $data['target'],
        ]);

        $this->setNotifyWhenReached($goal, (bool) ($data['notify_when_reached'] ?? false));

        return $goal->fresh(['checkInTracker', 'reachedAlert']);
    }

    public function update(Goal $goal, array $data): Goal
    {
        $period = GoalPeriod::from($data['period'] ?? $goal->period->value);
        $this->validateTarget($goal->checkInTracker, $period, (float) ($data['target'] ?? $goal->target));

        $goal->update(array_intersect_key($data, array_flip(['period', 'comparison', 'target'])));

        if (array_key_exists('notify_when_reached', $data)) {
            $this->setNotifyWhenReached($goal, (bool) $data['notify_when_reached']);
        }

        return $goal->fresh(['checkInTracker', 'reachedAlert']);
    }

    public function delete(Goal $goal): void
    {
        $goal->delete();
    }

    // "Notify me when reached" is an alert on the goal; the switch is on exactly when that alert exists.
    private function setNotifyWhenReached(Goal $goal, bool $notify): void
    {
        if (! $notify) {
            $goal->reachedAlert()->delete();

            return;
        }

        Alert::firstOrCreate(
            ['goal_id' => $goal->id, 'rule' => AlertRule::GoalReached],
            ['user_id' => $goal->user_id, 'source' => AlertSource::Goal]
        );
    }

    private function validateTarget(CheckInTracker $tracker, GoalPeriod $period, float $target): void
    {
        $error = match ($tracker->type) {
            CheckInTrackerType::Scale => $this->averageTargetError($target),
            CheckInTrackerType::YesNo, CheckInTrackerType::Sport => $this->daysTargetError($period, $target),
            CheckInTrackerType::Count => $this->countTargetError($target),
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['target' => $error]);
        }
    }

    private function averageTargetError(float $target): ?string
    {
        $onScale = $target >= 1 && $target <= 5;

        return $onScale ? null : 'A 1–5 goal needs an average between 1 and 5.';
    }

    private function daysTargetError(GoalPeriod $period, float $target): ?string
    {
        if ($period === GoalPeriod::Day) {
            return 'Count days per week or per month for this tracker.';
        }

        $fitsPeriod = $this->isWhole($target) && $target >= 0 && $target <= $period->maxDays();

        return $fitsPeriod ? null : 'The number of days does not fit the period.';
    }

    private function countTargetError(float $target): ?string
    {
        $inRange = $this->isWhole($target) && $target >= 0 && $target <= CheckInTrackerType::MAX_COUNT;

        return $inRange ? null : 'The target must be a whole number from 0 to '.CheckInTrackerType::MAX_COUNT.'.';
    }

    private function isWhole(float $value): bool
    {
        return floor($value) === $value;
    }
}
