<?php

namespace App\Api\Contacts\Factories;

use App\Api\Contacts\Models\AddressBook;
use App\Api\Contacts\Models\Contact;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'address_book_id' => AddressBook::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'middle_name' => $this->faker->optional(0.3)->firstName(),
            'email' => $this->faker->optional(0.7)->email(),
            'phone' => $this->faker->optional(0.6)->phoneNumber(),
            'organization' => $this->faker->optional(0.4)->company(),
            'note' => $this->faker->optional(0.3)->sentence(),
            'address' => $this->faker->optional(0.4)->streetAddress(),
            'city' => $this->faker->optional(0.4)->city(),
            'postal_code' => $this->faker->optional(0.4)->postcode(),
            'country' => $this->faker->optional(0.4)->country(),
            'groups' => [],
            'photo' => null,
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => $this->faker->email(),
        ]);
    }

    public function withPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => $this->faker->phoneNumber(),
        ]);
    }

    public function withAddress(): static
    {
        return $this->state(fn (array $attributes) => [
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'postal_code' => $this->faker->postcode(),
            'country' => $this->faker->country(),
        ]);
    }

    public function withOrganization(): static
    {
        return $this->state(fn (array $attributes) => [
            'organization' => $this->faker->company(),
        ]);
    }

    public function withName(string $firstName, string $lastName): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}
