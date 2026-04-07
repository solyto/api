<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryGameGenre;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryGameGenreFactory extends Factory
{
    protected $model = LibraryGameGenre::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Action', 'Adventure', 'RPG', 'Strategy', 'Simulation', 'Sports', 'Racing', 'Puzzle']),
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
