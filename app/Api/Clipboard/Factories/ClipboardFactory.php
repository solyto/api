<?php

namespace App\Api\Clipboard\Factories;

use App\Api\Clipboard\Models\Clipboard;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClipboardFactory extends Factory
{
    protected $model = Clipboard::class;

    public function definition(): array
    {
        return [
            'content' => $this->faker->sentence(),
            'user_id' => User::factory(),
            'type' => 'text',
            'file_path' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withContent(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $content,
        ]);
    }

    public function withUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->faker->url(),
        ]);
    }

    public function asFile(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->faker->word().'.'.$this->faker->fileExtension(),
            'type' => 'file',
            'file_path' => $this->faker->filePath(),
        ]);
    }

    public function asCode(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $this->faker->randomElement(['function example() { return true; }', 'const x = 42;', 'SELECT * FROM users;', '.class { color: red; }']),
            'type' => 'code',
        ]);
    }
}
