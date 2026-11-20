<?php

namespace App\Api\CheckIn\Factories;

use App\Api\CheckIn\Models\CheckInAlert;
use App\Api\CheckIn\Models\CheckInTracker;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CheckInAlert> */
class CheckInAlertFactory extends Factory
{
    protected $model = CheckInAlert::class;

    public function definition(): array
    {
        return [
            'check_in_tracker_id' => CheckInTracker::factory(),
            'time' => '20:00',
            'target' => null,
            'last_sent_on' => null,
        ];
    }

    public function forTracker(CheckInTracker $tracker): static
    {
        return $this->state(fn (array $attributes) => [
            'check_in_tracker_id' => $tracker->id,
        ]);
    }

    public function at(string $time, ?int $target = null): static
    {
        return $this->state(fn (array $attributes) => [
            'time' => $time,
            'target' => $target,
        ]);
    }
}
