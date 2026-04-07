<?php

namespace App\Api\TimeTracking\Factories;

use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeTrackingCategoryFactory extends Factory
{
    protected $model = TimeTrackingCategory::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Development', 'Design', 'Meetings', 'Research', 'Documentation']),
            'color' => $this->faker->hexColor(),
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

    public function withColor(string $color): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => $color,
        ]);
    }
}
