<?php

namespace App\Api\DevRequests\Factories;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestVote;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DevRequestVoteFactory extends Factory
{
    protected $model = DevRequestVote::class;

    public function definition(): array
    {
        return [
            'dev_request_id' => DevRequest::factory(),
            'user_id' => User::factory(),
            'vote_type' => $this->faker->randomElement(['up', 'down']),
        ];
    }

    public function upvote(): static
    {
        return $this->state(fn (array $attributes) => [
            'vote_type' => 'up',
        ]);
    }

    public function downvote(): static
    {
        return $this->state(fn (array $attributes) => [
            'vote_type' => 'down',
        ]);
    }
}
