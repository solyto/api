<?php

namespace App\Api\Finances\Factories;

use App\Api\Finances\Models\WealthField;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WealthFieldFactory extends Factory
{
    protected $model = WealthField::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Savings Account', 'Investments', 'Cash', 'Retirement Fund']),
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
