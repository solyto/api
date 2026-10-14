<?php

namespace App\Api\Notifications\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'music_release_ui' => 'sometimes|boolean',
            'music_release_email' => 'sometimes|boolean',
            'music_release_push' => 'sometimes|boolean',
            'music_release_telegram' => 'sometimes|boolean',
            'book_release_ui' => 'sometimes|boolean',
            'book_release_email' => 'sometimes|boolean',
            'book_release_push' => 'sometimes|boolean',
            'book_release_telegram' => 'sometimes|boolean',
            'friend_request_ui' => 'sometimes|boolean',
            'friend_request_email' => 'sometimes|boolean',
            'friend_request_push' => 'sometimes|boolean',
            'friend_request_telegram' => 'sometimes|boolean',
            'daily_day_reminder_ui' => 'sometimes|boolean',
            'daily_day_reminder_email' => 'sometimes|boolean',
            'daily_day_reminder_push' => 'sometimes|boolean',
            'daily_day_reminder_telegram' => 'sometimes|boolean',
            'daily_check_in_reminder_ui' => 'sometimes|boolean',
            'daily_check_in_reminder_email' => 'sometimes|boolean',
            'daily_check_in_reminder_push' => 'sometimes|boolean',
            'daily_check_in_reminder_telegram' => 'sometimes|boolean',
            'calendar_share_ui' => 'sometimes|boolean',
            'calendar_share_email' => 'sometimes|boolean',
            'calendar_share_push' => 'sometimes|boolean',
            'calendar_share_telegram' => 'sometimes|boolean',
            'dev_request_comment_ui' => 'sometimes|boolean',
            'dev_request_comment_email' => 'sometimes|boolean',
            'dev_request_comment_push' => 'sometimes|boolean',
            'dev_request_comment_telegram' => 'sometimes|boolean',
            'export_ready_ui' => 'sometimes|boolean',
            'export_ready_email' => 'sometimes|boolean',
            'export_ready_push' => 'sometimes|boolean',
            'export_ready_telegram' => 'sometimes|boolean',
            'movie_release_ui' => 'sometimes|boolean',
            'movie_release_email' => 'sometimes|boolean',
            'movie_release_push' => 'sometimes|boolean',
            'movie_release_telegram' => 'sometimes|boolean',
        ];
    }
}
