<?php

namespace App\Api\Todos\Factories;

use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoSubtask;
use Illuminate\Database\Eloquent\Factories\Factory;

class TodoSubtaskFactory extends Factory
{
    protected $model = TodoSubtask::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'todo_id' => Todo::factory(),
            'is_completed' => $this->faker->boolean(40),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => true,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => false,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }
}
