<?php

namespace App\Api\Finances\Factories;

use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use Illuminate\Database\Eloquent\Factories\Factory;

class WealthValueFactory extends Factory
{
    protected $model = WealthValue::class;

    public function definition(): array
    {
        return [
            'date' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'value' => $this->faker->randomFloat(2, 0, 100000),
            'field_id' => WealthField::factory(),
        ];
    }

    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => now(),
        ]);
    }

    public function forField(WealthField $field): static
    {
        return $this->state(fn (array $attributes) => [
            'field_id' => $field->id,
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
