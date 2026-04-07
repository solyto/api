<?php

namespace App\Api\Todos\Factories;

use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TodoFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Todo::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(rand(3, 8)),
            'description' => $this->faker->optional(0.7)->paragraph(),
            'is_completed' => $this->faker->boolean(30),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high']),
            'due_at' => $this->faker->optional(0.8)->date(),
            'user_id' => User::factory(),
            'effort' => $this->faker->optional(0.5)->numberBetween(1, 8),
            'progress' => $this->faker->optional(0.3)->numberBetween(0, 100),
            'status' => $this->faker->optional(0.5)->randomElement(['backlog', 'in_progress', 'review', 'blocked']),
        ];
    }

    /**
     * State: Create a completed todo
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => true,
        ]);
    }

    /**
     * State: Create a pending todo
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => false,
        ]);
    }

    /**
     * State: Create a high priority todo
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
        ]);
    }

    /**
     * State: Create a low priority todo
     */
    public function lowPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'low',
        ]);
    }

    /**
     * State: Create an overdue todo
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => $this->faker->dateTimeBetween('-30 days', '-1 day'),
            'is_completed' => false,
        ]);
    }

    public function dueToday(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => today(),
            'is_completed' => false,
        ]);
    }

    public function noDueDate(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => null,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
