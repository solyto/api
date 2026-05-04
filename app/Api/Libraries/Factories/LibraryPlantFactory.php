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
            'type' => $this->faker->randomElement(['succulent', 'tropical', 'herb', 'fern', null]),
            'location' => $this->faker->randomElement(['indoor', 'outdoor', 'both']),
            'sunlight' => $this->faker->randomElement(['full_sun', 'partial_sun', 'indirect', 'shade']),
            'watering_interval' => $this->faker->numberBetween(3, 14),
            'fertilizing_interval' => null,
            'current_size' => null,
            'acquired_at' => null,
            'toxicity' => $this->faker->boolean(20),
            'winter_hardy' => null,
            'pruning_instructions' => null,
            'repotting_instructions' => null,
            'notes' => null,
            'rating' => $this->faker->optional(0.5)->numberBetween(1, 5),
            'cover_path' => null,
            'link' => $this->faker->optional(0.2)->url(),
            'wishlist' => $this->faker->boolean(10),
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
