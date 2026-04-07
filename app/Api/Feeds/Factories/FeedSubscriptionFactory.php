<?php

namespace App\Api\Feeds\Factories;

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedSubscriptionFactory extends Factory
{
    protected $model = FeedSubscription::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Tech News', 'Personal Blog', 'News Updates']),
            'whitelist' => [],
            'blacklist' => [],
            'user_id' => User::factory(),
            'feed_id' => Feed::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }

    public function withWhitelist(array $keywords): static
    {
        return $this->state(fn (array $attributes) => [
            'whitelist' => $keywords,
        ]);
    }

    public function withBlacklist(array $keywords): static
    {
        return $this->state(fn (array $attributes) => [
            'blacklist' => $keywords,
        ]);
    }
}
