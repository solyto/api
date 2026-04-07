<?php

namespace App\Api\Telegram\Factories;

use App\Api\Telegram\Models\TelegramBotConnection;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TelegramBotConnectionFactory extends Factory
{
    protected $model = TelegramBotConnection::class;

    public function definition(): array
    {
        return [
            'token' => $this->faker->sha256(),
            'is_confirmed' => false,
            'chat_id' => null,
            'user_id' => User::factory(),
            'your_day_alert' => false,
            'check_in_alert' => false,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_confirmed' => true,
            'chat_id' => $this->faker->numberBetween(1000000, 999999999),
        ]);
    }

    public function withYourDayAlert(): static
    {
        return $this->state(fn (array $attributes) => [
            'your_day_alert' => true,
        ]);
    }

    public function withCheckInAlert(): static
    {
        return $this->state(fn (array $attributes) => [
            'check_in_alert' => true,
        ]);
    }

    public function withAllAlerts(): static
    {
        return $this->state(fn (array $attributes) => [
            'your_day_alert' => true,
            'check_in_alert' => true,
        ]);
    }
}
