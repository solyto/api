<?php

namespace App\Api\Users\Factories;

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserSettingsFactory extends Factory
{
    protected $model = UserSettings::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'navigation' => $this->faker->randomElement(['sidebar', 'top']),
            'timezone' => $this->faker->timezone(),
            'date_format' => $this->faker->randomElement(['Y-m-d', 'd/m/Y', 'm/d/Y']),
            'time_format' => $this->faker->randomElement(['24h', '12h']),
            'language' => $this->faker->randomElement(['en', 'de', 'fr', 'es']),
            'ai_enabled' => $this->faker->boolean(40),
            'openai_api_key' => $this->faker->optional(0.1)->sha256(),
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

    public function withAiEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_enabled' => true,
            'openai_api_key' => 'sk-'.$this->faker->sha256(),
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
