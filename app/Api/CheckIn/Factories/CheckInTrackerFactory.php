<?php

namespace App\Api\CheckIn\Factories;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckInTracker> */
class CheckInTrackerFactory extends Factory
{
    protected $model = CheckInTracker::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => null,
            'name' => $this->faker->words(2, true),
            'type' => CheckInTrackerType::YesNo,
            'icon' => 'sparkles',
            'position' => 100,
            'enabled' => true,
            'scored' => false,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function ofType(CheckInTrackerType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'scored' => $type === CheckInTrackerType::Scale,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}
