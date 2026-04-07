<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryBook;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryBookFactory extends Factory
{
    protected $model = LibraryBook::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'author' => $this->faker->name(),
            'series' => $this->faker->optional(0.3)->sentence(2),
            'volume' => $this->faker->optional(0.3)->numberBetween(1, 10),
            'rating' => $this->faker->numberBetween(1, 5),
            'publication_year' => $this->faker->numberBetween(1950, 2024),
            'pages' => $this->faker->numberBetween(100, 1000),
            'current_page' => null,
            'lent_to' => null,
            'is_where' => $this->faker->optional(0.3)->sentence(2),
            'cover_path' => null,
            'link' => $this->faker->optional(0.4)->url(),
            'wishlist' => $this->faker->boolean(10),
            'summary' => $this->faker->optional(0.5)->paragraph(),
            'user_id' => User::factory(),
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_page' => $this->faker->numberBetween(1, $attributes['pages'] ?? 500),
            'started_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_page' => $attributes['pages'] ?? 300,
            'started_at' => $this->faker->dateTimeBetween('-60 days', '-10 days'),
            'finished_at' => $this->faker->dateTimeBetween('-10 days', 'now'),
        ]);
    }

    public function wishlist(): static
    {
        return $this->state(fn (array $attributes) => [
            'wishlist' => true,
        ]);
    }

    public function lent(): static
    {
        return $this->state(fn (array $attributes) => [
            'lent_to' => $this->faker->name(),
        ]);
    }

    public function highRated(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $this->faker->numberBetween(4, 5),
        ]);
    }
}
