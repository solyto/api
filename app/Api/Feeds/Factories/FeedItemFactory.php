<?php

namespace App\Api\Feeds\Factories;

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedItemFactory extends Factory
{
    protected $model = FeedItem::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(8),
            'link' => $this->faker->url(),
            'description' => $this->faker->paragraph(),
            'published_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'feed_id' => Feed::factory(),
            'feed_item_id' => $this->faker->uuid(),
        ];
    }

    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => now(),
        ]);
    }

    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }
}
