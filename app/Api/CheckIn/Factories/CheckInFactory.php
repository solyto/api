<?php

namespace App\Api\CheckIn\Factories;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CheckInFactory extends Factory
{
    protected $model = CheckIn::class;

    public function definition(): array
    {
        return [
            'date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'mood' => $this->faker->numberBetween(1, 5),
            'water' => $this->faker->numberBetween(0, 10),
            'sports' => $this->faker->numberBetween(0, 120),
            'sleep' => $this->faker->numberBetween(0, 12),
            'dreams' => $this->faker->optional(0.4)->sentence(),
            'work' => $this->faker->numberBetween(0, 12),
            'food_quality' => $this->faker->numberBetween(1, 5),
            'food_amount' => $this->faker->numberBetween(1, 5),
            'menstruation' => $this->faker->boolean(10),
            'alcohol' => $this->faker->boolean(20),
            'smoking' => $this->faker->boolean(10),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => now(),
        ]);
    }

    public function forDate(\DateTime $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    public function greatDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'mood' => $this->faker->numberBetween(4, 5),
            'water' => $this->faker->numberBetween(7, 10),
            'sleep' => $this->faker->numberBetween(7, 9),
        ]);
    }

    public function poorDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'mood' => $this->faker->numberBetween(1, 2),
            'water' => $this->faker->numberBetween(0, 3),
            'sleep' => $this->faker->numberBetween(0, 5),
        ]);
    }
}
