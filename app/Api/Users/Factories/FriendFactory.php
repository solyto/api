<?php

namespace App\Api\Users\Factories;

use App\Api\Users\Models\Friend;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FriendFactory extends Factory
{
    protected $model = Friend::class;

    public function definition(): array
    {
        return [
            'user_id_1' => User::factory(),
            'user_id_2' => User::factory(),
            'friends_since' => $this->faker->dateTimeBetween('-2 years', 'now'),
        ];
    }

    public function forUsers(User $user1, User $user2): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id_1' => $user1->id,
            'user_id_2' => $user2->id,
        ]);
    }

    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'friends_since' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function longTerm(): static
    {
        return $this->state(fn (array $attributes) => [
            'friends_since' => $this->faker->dateTimeBetween('-5 years', '-2 years'),
        ]);
    }
}
