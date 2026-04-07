<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryLinkCategoryFactory extends Factory
{
    protected $model = LibraryLinkCategory::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Work', 'Personal', 'Learning', 'Entertainment', 'News', 'Social Media']),
            'color' => $this->faker->hexColor(),
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
