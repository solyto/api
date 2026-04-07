<?php

namespace App\Api\Libraries\Factories;

use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LibraryQuoteFactory extends Factory
{
    protected $model = LibraryQuote::class;

    public function definition(): array
    {
        return [
            'summary' => $this->faker->sentence(4),
            'author' => $this->faker->name(),
            'quote' => $this->faker->sentence(12),
            'source' => $this->faker->optional(0.6)->sentence(2),
            'user_id' => User::factory(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withAuthor(string $author): static
    {
        return $this->state(fn (array $attributes) => [
            'author' => $author,
        ]);
    }

    public function withSource(string $source): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => $source,
        ]);
    }

    public function inspirational(): static
    {
        return $this->state(fn (array $attributes) => [
            'quote' => $this->faker->randomElement([
                'The only way to do great work is to love what you do.',
                'In the middle of difficulty lies opportunity.',
                'Success is not final, failure is not fatal: it is the courage to continue that counts.',
                'Believe you can and you\'re halfway there.',
                'The best time to plant a tree was 20 years ago. The second best time is now.',
            ]),
        ]);
    }

    public function short(): static
    {
        return $this->state(fn (array $attributes) => [
            'quote' => $this->faker->sentence(3),
        ]);
    }
}
