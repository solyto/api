<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryRecipeFactory extends Factory
{
    protected $model = LibraryRecipe::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'rating' => $this->faker->numberBetween(1, 5),
            'time_to_make' => $this->faker->numberBetween(15, 180),
            'description' => $this->faker->optional(0.7)->paragraph(),
            'ingredients' => $this->faker->randomElement([
                'Flour, sugar, eggs, butter',
                'Pasta, tomato sauce, basil, parmesan',
                'Chicken, rice, vegetables, soy sauce',
                'Beef, potatoes, carrots, onions',
                'Fish, lemon, herbs, olive oil',
            ]),
            'type' => $this->faker->randomElement(['breakfast', 'lunch', 'dinner', 'snack', 'dessert']),
            'cover_path' => null,
            'link' => $this->faker->optional(0.3)->url(),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function quick(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_to_make' => $this->faker->numberBetween(15, 30),
        ]);
    }

    public function timeConsuming(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_to_make' => $this->faker->numberBetween(60, 180),
        ]);
    }

    public function highRated(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $this->faker->numberBetween(4, 5),
        ]);
    }

    public function withType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }
}
