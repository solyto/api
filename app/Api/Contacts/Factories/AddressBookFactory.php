<?php

namespace App\Api\Contacts\Factories;

use App\Api\Contacts\Models\AddressBook;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressBookFactory extends Factory
{
    protected $model = AddressBook::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->randomElement(['Personal', 'Work', 'Family', 'Friends', 'Business']),
            'color' => $this->faker->hexColor(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withTitle(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
        ]);
    }

    public function withColor(string $color): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => $color,
        ]);
    }
}
