<?php

namespace App\Api\DevRequests\Factories;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DevRequest> */
class DevRequestFactory extends Factory
{
    protected $model = DevRequest::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['feature', 'bug']),
            'status' => $this->faker->randomElement(['backlog', 'pending', 'in-progress', 'waiting-for-deployment', 'completed', 'cancelled']),
            'title' => $this->faker->sentence(6),
            'description' => $this->faker->paragraph(),
            'screenshot' => null,
            'url' => $this->faker->optional(0.3)->url(),
            'priority' => $this->faker->numberBetween(1, 5),
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

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in-progress',
        ]);
    }

    public function waitingForDeployment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'waiting-for-deployment',
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
            'priority' => 5,
        ]);
    }

    public function withUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'url' => $this->faker->url(),
        ]);
    }
}
