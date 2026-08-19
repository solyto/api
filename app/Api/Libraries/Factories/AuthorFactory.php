<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\Author;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuthorFactory extends Factory
{
    protected $model = Author::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'photo' => null,
            'bio' => null,
            'hardcover_id' => null,
            'is_favorite' => false,
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

    public function notFavorite(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_favorite' => false,
        ]);
    }

    public function withName(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
        ]);
    }

    public function withHardcoverId(int $hardcoverId): static
    {
        return $this->state(fn (array $attributes) => [
            'hardcover_id' => $hardcoverId,
        ]);
    }
}
