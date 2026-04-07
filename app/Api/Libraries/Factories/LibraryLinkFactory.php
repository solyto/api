<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryLinkFactory extends Factory
{
    protected $model = LibraryLink::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'url' => $this->faker->url(),
            'is_favorite' => $this->faker->boolean(20),
            'user_id' => User::factory(),
            'category_id' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function favorite(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_favorite' => true,
        ]);
    }

    public function withCategory(LibraryLinkCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    public function withUrl(string $url): static
    {
        return $this->state(fn (array $attributes) => [
            'url' => $url,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }
}
