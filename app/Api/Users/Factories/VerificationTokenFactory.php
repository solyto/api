<?php

namespace App\Api\Users\Factories;

use App\Api\Users\Models\User;
use App\Api\Users\Models\VerificationToken;
use Illuminate\Database\Eloquent\Factories\Factory;

class VerificationTokenFactory extends Factory
{
    protected $model = VerificationToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => $this->faker->sha256(),
            'expires_at' => $this->faker->dateTimeBetween('now', '+24 hours'),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => $this->faker->dateTimeBetween('-24 hours', '-1 hour'),
        ]);
    }

    public function expiresSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => $this->faker->dateTimeBetween('now', '+1 hour'),
        ]);
    }

    public function expiresInDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => $this->faker->dateTimeBetween('now', '+24 hours'),
        ]);
    }
}
