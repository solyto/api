<?php

namespace App\Api\Calendars\Factories;

use App\Api\Calendars\Models\ExternalCalendar;
use App\Api\Calendars\Models\ExternalCalendarAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalCalendar> */
class ExternalCalendarFactory extends Factory
{
    protected $model = ExternalCalendar::class;

    public function definition(): array
    {
        return [
            'external_calendar_account_id' => ExternalCalendarAccount::factory(),
            'user_id' => fn (array $attributes) => ExternalCalendarAccount::find($attributes['external_calendar_account_id'])?->user_id,
            'remote_url' => 'https://dav.example.com/calendars/'.$this->faker->slug(2).'/',
            'calendar_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'instance_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
        ];
    }

    public function forAccount(ExternalCalendarAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'external_calendar_account_id' => $account->id,
            'user_id' => $account->user_id,
        ]);
    }
}
