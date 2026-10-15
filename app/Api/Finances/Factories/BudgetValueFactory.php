<?php

namespace App\Api\Finances\Factories;

use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\BudgetValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BudgetValue> */
class BudgetValueFactory extends Factory
{
    protected $model = BudgetValue::class;

    public function definition(): array
    {
        return [
            'date' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'value' => $this->faker->randomFloat(2, 10, 5000),
            'budget_id' => Budget::factory(),
        ];
    }

    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => now(),
        ]);
    }

    public function forBudget(Budget $budget): static
    {
        return $this->state(fn (array $attributes) => [
            'budget_id' => $budget->id,
        ]);
    }

    public function withValue(float $value): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => $value,
        ]);
    }

    public function historical(int $daysBack): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => now()->subDays($daysBack),
        ]);
    }
}
