<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryMusicFactory extends Factory
{
    protected $model = LibraryMusic::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'artist' => $this->faker->name(),
            'type' => $this->faker->randomElement(['album', 'single', 'ep', 'compilation']),
            'format' => $this->faker->randomElement(['vinyl', 'cd', 'digital', 'cassette']),
            'condition' => $this->faker->randomElement(['new', 'like new', 'good', 'fair', 'poor']),
            'rating' => $this->faker->numberBetween(1, 5),
            'publication_year' => $this->faker->numberBetween(1960, 2024),
            'acquired_where' => $this->faker->optional(0.4)->sentence(2),
            'additional_info' => $this->faker->optional(0.3)->paragraph(),
            'cover_path' => null,
            'wishlist' => $this->faker->boolean(10),
            'link' => $this->faker->optional(0.3)->url(),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function album(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'album',
        ]);
    }

    public function wishlist(): static
    {
        return $this->state(fn (array $attributes) => [
            'wishlist' => true,
        ]);
    }

    public function vinyl(): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => 'vinyl',
        ]);
    }

    public function highRated(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $this->faker->numberBetween(4, 5),
        ]);
    }
}
