<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryBookGenre;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryBookGenreFactory extends Factory
{
    protected $model = LibraryBookGenre::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Fiction', 'Non-Fiction', 'Sci-Fi', 'Fantasy', 'Mystery', 'Thriller', 'Romance', 'Biography']),
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
