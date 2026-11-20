<?php

namespace App\Api\Alerts\Factories;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Enums\AlertSource;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Goals\Models\Goal;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alert> */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => AlertSource::CheckInTracker,
            'check_in_tracker_id' => null,
            'goal_id' => null,
            'rule' => AlertRule::NoEntryDays,
            'threshold' => null,
            'days' => 7,
            'enabled' => true,
            'suggestion' => null,
            'is_triggered' => false,
            'last_triggered_on' => null,
        ];
    }

    public function forTracker(CheckInTracker $tracker): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $tracker->user_id,
            'source' => AlertSource::CheckInTracker,
            'check_in_tracker_id' => $tracker->id,
        ]);
    }

    public function forGoal(Goal $goal): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $goal->user_id,
            'source' => AlertSource::Goal,
            'goal_id' => $goal->id,
            'rule' => AlertRule::GoalReached,
            'days' => null,
        ]);
    }

    public function rule(AlertRule $rule, ?float $threshold = null, ?int $days = 7): static
    {
        return $this->state(fn (array $attributes) => [
            'rule' => $rule,
            'threshold' => $threshold,
            'days' => $days,
        ]);
    }
}
