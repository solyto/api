<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryGame;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryGameFactory extends Factory
{
    protected $model = LibraryGame::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'rating' => $this->faker->numberBetween(1, 5),
            'publication_year' => $this->faker->numberBetween(1990, 2024),
            'platform' => $this->faker->randomElement(['PC', 'PlayStation', 'Xbox', 'Nintendo Switch', 'Mobile']),
            'developer' => $this->faker->company(),
            'publisher' => $this->faker->company(),
            'playtime_hours' => null,
            'completed' => $this->faker->boolean(30),
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

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'playtime_hours' => $this->faker->numberBetween(1, 50),
            'completed' => false,
            'started_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'playtime_hours' => $this->faker->numberBetween(10, 200),
            'completed' => true,
            'started_at' => $this->faker->dateTimeBetween('-180 days', '-30 days'),
            'finished_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function wishlist(): static
    {
        return $this->state(fn (array $attributes) => [
            'wishlist' => true,
            'playtime_hours' => null,
            'completed' => false,
        ]);
    }

    public function highRated(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $this->faker->numberBetween(4, 5),
        ]);
    }

    public function withPlatform(string $platform): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => $platform,
        ]);
    }
}
