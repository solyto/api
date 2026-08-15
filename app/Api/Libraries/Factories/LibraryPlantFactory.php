<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryPlant;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryPlantFactory extends Factory
{
    protected $model = LibraryPlant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'latin_name' => null,
            'location' => $this->faker->randomElement(['indoor', 'outdoor', 'both']),
            'sunlight' => $this->faker->randomElement(['full_sun', 'partial_sun', 'indirect', 'shade']),
            'current_size' => null,
            'max_size' => null,
            'acquired_at' => null,
            'winter_hardy' => null,
            'instructions' => null,
            'cover_path' => null,
            'link' => $this->faker->optional(0.2)->url(),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
