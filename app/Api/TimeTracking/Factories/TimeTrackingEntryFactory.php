<?php

namespace App\Api\TimeTracking\Factories;

use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeTrackingEntryFactory extends Factory
{
    protected $model = TimeTrackingEntry::class;

    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-30 days', 'now');
        $duration = $this->faker->numberBetween(15, 480);

        return [
            'description' => $this->faker->sentence(6),
            'started_at' => $startedAt,
            'stopped_at' => (clone $startedAt)->modify('+'.$duration.' minutes'),
            'duration_minutes' => $duration,
            'project_id' => TimeTrackingProject::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withProject(TimeTrackingProject $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
        ]);
    }

    public function withDuration(int $minutes): static
    {
        return $this->state(function (array $attributes) use ($minutes) {
            $startedAt = $attributes['started_at'] ?? now();

            return [
                'duration_minutes' => $minutes,
                'stopped_at' => (clone $startedAt)->modify('+'.$minutes.' minutes'),
            ];
        });
    }

    public function today(): static
    {
        $startedAt = now()->subHours(rand(1, 8));
        $duration = rand(15, 120);

        return $this->state(fn (array $attributes) => [
            'started_at' => $startedAt,
            'stopped_at' => (clone $startedAt)->modify('+'.$duration.' minutes'),
            'duration_minutes' => $duration,
        ]);
    }

    public function short(): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_minutes' => $this->faker->numberBetween(15, 60),
        ]);
    }

    public function long(): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_minutes' => $this->faker->numberBetween(240, 480),
        ]);
    }
}
