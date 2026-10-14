<?php

namespace App\Api\Users\Factories;

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserSettings> */
class UserSettingsFactory extends Factory
{
    protected $model = UserSettings::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'navigation' => $this->faker->randomElement(['sidebar', 'top']),
            'timezone' => $this->faker->randomElement(['Europe/Berlin', 'UTC', 'America/New_York', 'Asia/Tokyo']),
            'date_format' => $this->faker->randomElement(['Y-m-d', 'd/m/Y', 'm/d/Y']),
            'time_format' => $this->faker->randomElement(['24h', '12h']),
            'language' => $this->faker->randomElement(['en', 'de', 'fr', 'es']),
            'first_visit' => false,
            'check_in_settings' => [],
            'weather_city' => $this->faker->optional(0.5)->city(),
            'weather_latitude' => $this->faker->optional(0.5)->latitude(),
            'weather_longitude' => $this->faker->optional(0.5)->longitude(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withWeatherLocation(): static
    {
        return $this->state(fn (array $attributes) => [
            'weather_city' => $this->faker->city(),
            'weather_latitude' => $this->faker->latitude(),
            'weather_longitude' => $this->faker->longitude(),
        ]);
    }
}
