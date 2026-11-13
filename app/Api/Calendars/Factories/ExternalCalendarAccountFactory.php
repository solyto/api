<?php

namespace App\Api\Calendars\Factories;

use App\Api\Calendars\Models\ExternalCalendarAccount;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalCalendarAccount> */
class ExternalCalendarAccountFactory extends Factory
{
    protected $model = ExternalCalendarAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'server_url' => 'https://dav.example.com/',
            'username' => $this->faker->userName(),
            'password' => $this->faker->password(16),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
