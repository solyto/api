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
            'calories' => $this->faker->optional(0.6)->numberBetween(150, 900),
            'time_to_make' => $this->faker->numberBetween(15, 180),
            'servings' => $this->faker->numberBetween(1, 6),
            'description' => $this->faker->optional(0.7)->paragraph(),
            'steps' => $this->faker->randomElement([
                ['Preheat the oven to 180°C.', 'Mix all dry ingredients.', 'Bake for 25 minutes.'],
                ['Bring water to a boil.', 'Cook the pasta al dente.', 'Toss with the sauce and serve.'],
                ['Sear the meat on both sides.', 'Add the vegetables.', 'Simmer for one hour.'],
            ]),
            'ingredients' => $this->faker->randomElement([
                [
                    ['name' => 'Flour', 'amount' => 500, 'unit' => 'g'],
                    ['name' => 'Sugar', 'amount' => 200, 'unit' => 'g'],
                    ['name' => 'Eggs', 'amount' => 3, 'unit' => null],
                    ['name' => 'Butter', 'amount' => 250, 'unit' => 'g'],
                ],
                [
                    ['name' => 'Pasta', 'amount' => 400, 'unit' => 'g'],
                    ['name' => 'Tomato sauce', 'amount' => 500, 'unit' => 'ml'],
                    ['name' => 'Basil', 'amount' => null, 'unit' => null],
                    ['name' => 'Parmesan', 'amount' => 50, 'unit' => 'g'],
                ],
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
            'time_to_make' => $this->faker->numberBetween(15, 29),
        ]);
    }

    public function timeConsuming(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_to_make' => $this->faker->numberBetween(61, 180),
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
