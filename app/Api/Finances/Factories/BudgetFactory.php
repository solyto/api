<?php

namespace App\Api\Finances\Factories;

use App\Api\Finances\Models\Budget;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Monthly Groceries', 'Entertainment', 'Savings', 'Transport']),
            'type' => $this->faker->randomElement(['income', 'expense']),
            'value' => $this->faker->randomFloat(2, 10, 5000),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'income',
            'value' => $this->faker->randomFloat(2, 500, 10000),
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'expense',
            'value' => $this->faker->randomFloat(2, 10, 2000),
        ]);
    }

    public function withValue(float $value): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => $value,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }
}
