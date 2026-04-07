<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryMovieGenreFactory extends Factory
{
    protected $model = LibraryMovieGenre::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Action', 'Comedy', 'Drama', 'Horror', 'Sci-Fi', 'Thriller', 'Romance', 'Documentary']),
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
}
