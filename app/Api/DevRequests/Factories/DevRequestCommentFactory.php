<?php

namespace App\Api\DevRequests\Factories;

use App\Api\DevRequests\Models\DevRequest;
use App\Api\DevRequests\Models\DevRequestComment;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DevRequestCommentFactory extends Factory
{
    protected $model = DevRequestComment::class;

    public function definition(): array
    {
        return [
            'dev_request_id' => DevRequest::factory(),
            'user_id' => User::factory(),
            'content' => $this->faker->paragraph(),
        ];
    }

    public function withContent(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $content,
        ]);
    }

    public function short(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->faker->sentence(),
        ]);
    }
}
