<?php

namespace App\Api\Planner\Factories;

use App\Api\Planner\Models\PlannerEntry;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlannerEntryFactory extends Factory
{
    protected $model = PlannerEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->numberBetween(0, 1200);

        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'title' => $this->faker->sentence(3),
            'start_minutes' => $start,
            'duration_minutes' => $this->faker->numberBetween(15, min(240, 1440 - $start)),
            'is_all_day' => false,
            'color' => $this->faker->hexColor(),
            'todo_id' => null,
            'sort_order' => 0,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    public function allDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_all_day' => true,
            'start_minutes' => 0,
            'duration_minutes' => 1440,
        ]);
    }
}
