<?php

namespace App\Api\CheckIn\Factories;

use App\Api\CheckIn\Enums\CheckInGoalComparison;
use App\Api\CheckIn\Enums\CheckInGoalPeriod;
use App\Api\CheckIn\Models\CheckInGoal;
use App\Api\CheckIn\Models\CheckInTracker;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckInGoal> */
class CheckInGoalFactory extends Factory
{
    protected $model = CheckInGoal::class;

    public function definition(): array
    {
        return [
            'check_in_tracker_id' => CheckInTracker::factory(),
            'period' => CheckInGoalPeriod::Week,
            'comparison' => CheckInGoalComparison::AtLeast,
            'target' => 3,
        ];
    }

    public function forTracker(CheckInTracker $tracker): static
    {
        return $this->state(fn (array $attributes) => [
            'check_in_tracker_id' => $tracker->id,
        ]);
    }
}
