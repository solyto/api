<?php

namespace App\Api\Notes\Factories;

use App\Api\Notes\Models\Note;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->optional(0.9)->sentence(4),
            'content' => $this->faker->paragraphs(rand(2, 5), true),
            'user_id' => User::factory(),
            'category_id' => null,
            'is_favorite' => $this->faker->boolean(20),
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

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }

    public function short(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->faker->sentence(),
        ]);
    }

    public function long(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->faker->paragraphs(rand(10, 20), true),
        ]);
    }
}
