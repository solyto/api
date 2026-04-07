<?php

namespace App\Api\DevRequests\Factories;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DevRequestFactory extends Factory
{
    protected $model = DevRequest::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['feature', 'bug', 'improvement', 'other']),
            'status' => $this->faker->randomElement(['open', 'in_progress', 'completed', 'rejected']),
            'title' => $this->faker->sentence(6),
            'description' => $this->faker->paragraph(),
            'screenshot' => null,
            'url' => $this->faker->optional(0.3)->url(),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'created_by_user_id' => User::factory(),
        ];
    }

    public function feature(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'feature',
        ]);
    }

    public function bug(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'bug',
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
        ]);
    }

    public function withUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'url' => $this->faker->url(),
        ]);
    }
}
