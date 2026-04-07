<?php

namespace App\Api\Calendars\Factories;

use App\Api\Calendars\Models\Calendar;
use App\Api\Calendars\Models\CalendarEntry;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CalendarEntryFactory extends Factory
{
    protected $model = CalendarEntry::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('now', '+30 days');
        $isAllDay = $this->faker->boolean(30);

        return [
            'calendar_id' => Calendar::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->optional(0.6)->paragraph(),
            'start_date' => $startDate->getTimestamp(),
            'end_date' => $isAllDay ? $startDate->modify('+1 day')->getTimestamp() : $startDate->modify('+1 hour')->getTimestamp(),
            'is_all_day' => $isAllDay,
            'recurrence_end' => null,
            'recurrence_rule' => null,
            'timezone' => $this->faker->timezone(),
            'location' => $this->faker->optional(0.4)->address(),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function allDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_all_day' => true,
        ]);
    }

    public function withDuration(int $hours): static
    {
        return $this->state(fn (array $attributes) => [
            'is_all_day' => false,
            'end_date' => $attributes['start_date'] + ($hours * 3600),
        ]);
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence_rule' => $this->faker->randomElement(['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY']),
            'recurrence_end' => $this->faker->dateTimeBetween('+1 month', '+6 months')->getTimestamp(),
        ]);
    }

    public function withLocation(): static
    {
        return $this->state(fn (array $attributes) => [
            'location' => $this->faker->address(),
        ]);
    }

    public function today(): static
    {
        $today = now()->startOfDay();

        return $this->state(fn (array $attributes) => [
            'start_date' => $today->getTimestamp(),
            'end_date' => $today->copy()->addHours(2)->getTimestamp(),
        ]);
    }

    public function upcoming(int $days = 7): static
    {
        $startDate = now()->addDays(rand(1, $days));

        return $this->state(fn (array $attributes) => [
            'start_date' => $startDate->getTimestamp(),
            'end_date' => $startDate->copy()->addHours(rand(1, 4))->getTimestamp(),
        ]);
    }
}
