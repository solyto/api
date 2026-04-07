<?php

namespace App\Api\Shortcuts\Factories;

use App\Api\Shortcuts\Models\Shortcut;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShortcutFactory extends Factory
{
    protected $model = Shortcut::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Google', 'GitHub', 'YouTube', 'Stack Overflow', 'Twitter']),
            'url' => $this->faker->url(),
            'order' => $this->faker->numberBetween(0, 20),
            'user_id' => User::factory(),
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

    public function withUrl(string $url): static
    {
        return $this->state(fn (array $attributes) => [
            'url' => $url,
        ]);
    }

    public function withOrder(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }

    public function searchEngine(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $this->faker->randomElement(['Google', 'Bing', 'DuckDuckGo']),
            'url' => $this->faker->randomElement(['https://www.google.com', 'https://www.bing.com', 'https://duckduckgo.com']),
        ]);
    }

    public function socialMedia(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $this->faker->randomElement(['Twitter', 'LinkedIn', 'Instagram']),
            'url' => $this->faker->randomElement(['https://twitter.com', 'https://linkedin.com', 'https://instagram.com']),
        ]);
    }
}
