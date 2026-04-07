<?php

namespace App\Api\Feeds\Factories;

use App\Api\Feeds\Models\Feed;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedFactory extends Factory
{
    protected $model = Feed::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Tech News', 'Blog Updates', 'Product Release Notes', 'Company News']),
            'url' => $this->faker->url(),
            'created_by' => User::factory(),
        ];
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }

    public function withUrl(string $url): static
    {
        return $this->state(fn (array $attributes) => [
            'url' => $url,
        ]);
    }
}
