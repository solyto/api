<?php

namespace App\Api\Tables\Factories;

use App\Api\Tables\Models\Table;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TableFactory extends Factory
{
    protected $model = Table::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'icon' => null,
            'view' => 'list',
            'position' => 0,
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function cardView(): static
    {
        return $this->state(fn (array $attributes) => [
            'view' => 'card',
        ]);
    }
}
