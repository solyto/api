<?php

namespace App\Api\TimeTracking\Factories;

use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeTrackingProjectFactory extends Factory
{
    protected $model = TimeTrackingProject::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Website Redesign', 'Mobile App', 'API Development', 'Database Migration']),
            'description' => $this->faker->optional(0.7)->paragraph(),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }

    public function withDescription(string $description): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => $description,
        ]);
    }
}
