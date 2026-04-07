<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryMovieFactory extends Factory
{
    protected $model = LibraryMovie::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'rating' => $this->faker->numberBetween(1, 5),
            'publication_year' => $this->faker->numberBetween(1950, 2024),
            'category' => $this->faker->randomElement(['Movie', 'TV Series', 'Documentary', 'Short Film']),
            'cover_path' => null,
            'link' => $this->faker->optional(0.3)->url(),
            'wishlist' => $this->faker->boolean(10),
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

    public function movie(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'Movie',
        ]);
    }

    public function tvSeries(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'TV Series',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
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

    public function highRated(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $this->faker->numberBetween(4, 5),
        ]);
    }
}
