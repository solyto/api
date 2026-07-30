<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryYoutubeCategory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryYoutubeCategoryFactory extends Factory
{
    protected $model = LibraryYoutubeCategory::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Music Videos', 'Fun Stuff', 'Tutorials', 'Documentaries', 'Gaming', 'Podcasts']),
            'color' => $this->faker->hexColor(),
            'sort_order' => 0,
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

    public function withColor(string $color): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => $color,
        ]);
    }
}
