<?php

namespace App\Api\Calendars\Factories;

use App\Api\Calendars\Models\Calendar;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CalendarFactory extends Factory
{
    protected $model = Calendar::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->randomElement(['Personal', 'Work', 'Family', 'Holidays', 'Events']),
            'is_active' => $this->faker->boolean(90),
            'color' => $this->faker->hexColor(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }
}
