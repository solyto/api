<?php

namespace App\Api\Users\Factories;

use App\Api\Users\Models\User;
use App\Api\Users\Models\UserNotificationSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserNotificationSettingsFactory extends Factory
{
    protected $model = UserNotificationSettings::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'music_release_ui' => true,
            'music_release_email' => false,
            'music_release_push' => true,
            'music_release_telegram' => false,
            'book_release_ui' => true,
            'book_release_email' => false,
            'book_release_push' => true,
            'book_release_telegram' => false,
            'friend_request_ui' => true,
            'friend_request_email' => false,
            'friend_request_push' => true,
            'friend_request_telegram' => false,
            'daily_day_reminder_ui' => true,
            'daily_day_reminder_email' => false,
            'daily_day_reminder_push' => true,
            'daily_day_reminder_telegram' => false,
            'daily_check_in_reminder_ui' => true,
            'daily_check_in_reminder_email' => false,
            'daily_check_in_reminder_push' => true,
            'daily_check_in_reminder_telegram' => false,
            'calendar_share_ui' => true,
            'calendar_share_email' => false,
            'calendar_share_push' => true,
            'calendar_share_telegram' => false,
            'dev_request_comment_ui' => true,
            'dev_request_comment_email' => false,
            'dev_request_comment_push' => true,
            'dev_request_comment_telegram' => false,
            'movie_release_ui' => true,
            'movie_release_email' => false,
            'movie_release_push' => true,
            'movie_release_telegram' => false,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function telegramOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'music_release_telegram' => true,
            'music_release_ui' => false,
            'music_release_push' => false,
            'book_release_telegram' => true,
            'book_release_ui' => false,
            'book_release_push' => false,
        ]);
    }

    public function emailOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'friend_request_email' => true,
            'friend_request_ui' => false,
            'friend_request_push' => false,
            'daily_day_reminder_email' => true,
            'daily_day_reminder_ui' => false,
            'daily_day_reminder_push' => false,
        ]);
    }
}
