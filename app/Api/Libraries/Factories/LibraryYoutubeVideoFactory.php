<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryYoutubeCategory;
use App\Api\Libraries\Models\LibraryYoutubeVideo;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryYoutubeVideoFactory extends Factory
{
    protected $model = LibraryYoutubeVideo::class;

    public function definition(): array
    {
        $videoId = $this->faker->regexify('[A-Za-z0-9_-]{11}');

        return [
            'title' => $this->faker->sentence(4),
            'video_id' => $videoId,
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'is_favorite' => $this->faker->boolean(20),
            'sort_order' => 0,
            'category_id' => null,
            'user_id' => User::factory(),
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

    public function withCategory(LibraryYoutubeCategory $category): static
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
