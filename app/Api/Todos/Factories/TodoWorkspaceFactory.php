<?php

namespace App\Api\Todos\Factories;

use App\Api\Todos\Models\TodoWorkspace;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TodoWorkspaceFactory extends Factory
{
    protected $model = TodoWorkspace::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Work', 'Personal', 'Projects', 'Side Projects', 'Home']),
            'user_id' => User::factory(),
            'is_hideable' => $this->faker->boolean(50),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function hideable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_hideable' => true,
        ]);
    }

    public function notHideable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_hideable' => false,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }
}
