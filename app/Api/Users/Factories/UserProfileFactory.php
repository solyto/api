<?php

namespace App\Api\Users\Factories;

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserProfileFactory extends Factory
{
    protected $model = UserProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'profile_image_path' => $this->faker->optional(0.3)->imageUrl(400, 400, 'people'),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withProfileImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_image_path' => $this->faker->imageUrl(400, 400, 'people'),
        ]);
    }
}
