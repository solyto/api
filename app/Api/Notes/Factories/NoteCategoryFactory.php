<?php

namespace App\Api\Notes\Factories;

use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NoteCategoryFactory extends Factory
{
    protected $model = NoteCategory::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->randomElement(['Work', 'Personal', 'Ideas', 'Journal', 'Projects', 'Reference']),
            'user_id' => User::factory(),
            'parent_id' => null,
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function asRoot(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => null,
        ]);
    }

    public function withParent(NoteCategory $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }

    public function withSortOrder(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'sort_order' => $order,
        ]);
    }
}
