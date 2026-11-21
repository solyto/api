<?php

namespace App\Api\Goals\Factories;

use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Goals\Enums\GoalComparison;
use App\Api\Goals\Enums\GoalPeriod;
use App\Api\Goals\Enums\GoalSource;
use App\Api\Goals\Models\Goal;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => GoalSource::CheckInTracker,
            'check_in_tracker_id' => null,
            'measure' => null,
            'period' => GoalPeriod::Week,
            'comparison' => GoalComparison::AtLeast,
            'target' => 3,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function forTracker(CheckInTracker $tracker): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $tracker->user_id,
            'source' => GoalSource::CheckInTracker,
            'check_in_tracker_id' => $tracker->id,
        ]);
    }
}
