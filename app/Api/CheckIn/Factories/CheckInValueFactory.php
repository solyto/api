<?php

namespace App\Api\CheckIn\Factories;

use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\CheckIn\Models\CheckInValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckInValue> */
class CheckInValueFactory extends Factory
{
    protected $model = CheckInValue::class;

    public function definition(): array
    {
        return [
            'check_in_tracker_id' => CheckInTracker::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'value' => 1,
        ];
    }

    public function forTracker(CheckInTracker $tracker): static
    {
        return $this->state(fn (array $attributes) => [
            'check_in_tracker_id' => $tracker->id,
        ]);
    }

    public function on(string $date, int $value): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'value' => $value,
        ]);
    }
}
