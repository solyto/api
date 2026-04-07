<?php

namespace App\Api\Users\Factories;

use App\Api\Users\Models\FriendRequest;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FriendRequestFactory extends Factory
{
    protected $model = FriendRequest::class;

    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'receiver_id' => User::factory(),
            'status' => $this->faker->randomElement(['pending', 'accepted', 'rejected']),
        ];
    }

    public function forUsers(User $sender, User $receiver): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
        ]);
    }
}
