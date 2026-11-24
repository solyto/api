<?php

namespace App\Api\Calendars\Factories;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalCalendar> */
class ExternalCalendarFactory extends Factory
{
    protected $model = ExternalCalendar::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'external_calendar_account_id' => fn (array $attributes) => ExternalCalendarAccount::factory()->state(['user_id' => $attributes['user_id']]),
            'remote_url' => 'https://dav.example.com/calendars/'.$this->faker->slug(2).'/',
            'remote_url_hash' => fn (array $attributes) => hash('sha256', $attributes['remote_url']),
            'calendar_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'instance_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function forAccount(ExternalCalendarAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'external_calendar_account_id' => $account->id,
            'user_id' => $account->user_id,
        ]);
    }
}
