<?php

namespace App\Api\Todos\Factories;

use App\Api\Todos\Models\TodoCategory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TodoCategoryFactory extends Factory
{
    protected $model = TodoCategory::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Work', 'Personal', 'Shopping', 'Health', 'Ideas', 'Travel']),
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
}
