<?php

namespace App\Api\Notifications\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="NotificationSettings",
 *
 *     @OA\Property(property="music_release_ui", type="boolean"),
 *     @OA\Property(property="music_release_email", type="boolean"),
 *     @OA\Property(property="music_release_push", type="boolean"),
 *     @OA\Property(property="music_release_telegram", type="boolean"),
 *     @OA\Property(property="book_release_ui", type="boolean"),
 *     @OA\Property(property="book_release_email", type="boolean"),
 *     @OA\Property(property="book_release_push", type="boolean"),
 *     @OA\Property(property="book_release_telegram", type="boolean"),
 *     @OA\Property(property="friend_request_ui", type="boolean"),
 *     @OA\Property(property="friend_request_email", type="boolean"),
 *     @OA\Property(property="friend_request_push", type="boolean"),
 *     @OA\Property(property="friend_request_telegram", type="boolean"),
 *     @OA\Property(property="daily_day_reminder_ui", type="boolean"),
 *     @OA\Property(property="daily_day_reminder_email", type="boolean"),
 *     @OA\Property(property="daily_day_reminder_push", type="boolean"),
 *     @OA\Property(property="daily_day_reminder_telegram", type="boolean"),
 *     @OA\Property(property="daily_check_in_reminder_ui", type="boolean"),
 *     @OA\Property(property="daily_check_in_reminder_email", type="boolean"),
 *     @OA\Property(property="daily_check_in_reminder_push", type="boolean"),
 *     @OA\Property(property="daily_check_in_reminder_telegram", type="boolean"),
 *     @OA\Property(property="calendar_share_ui", type="boolean"),
 *     @OA\Property(property="calendar_share_email", type="boolean"),
 *     @OA\Property(property="calendar_share_push", type="boolean"),
 *     @OA\Property(property="calendar_share_telegram", type="boolean"),
 *     @OA\Property(property="dev_request_comment_ui", type="boolean"),
 *     @OA\Property(property="dev_request_comment_email", type="boolean"),
 *     @OA\Property(property="dev_request_comment_push", type="boolean"),
 *     @OA\Property(property="dev_request_comment_telegram", type="boolean")
 * )
 */
class NotificationSettingsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'music_release_ui' => (bool) $this->music_release_ui,
            'music_release_email' => (bool) $this->music_release_email,
            'music_release_push' => (bool) $this->music_release_push,
            'music_release_telegram' => (bool) $this->music_release_telegram,
            'book_release_ui' => (bool) $this->book_release_ui,
            'book_release_email' => (bool) $this->book_release_email,
            'book_release_push' => (bool) $this->book_release_push,
            'book_release_telegram' => (bool) $this->book_release_telegram,
            'friend_request_ui' => (bool) $this->friend_request_ui,
            'friend_request_email' => (bool) $this->friend_request_email,
            'friend_request_push' => (bool) $this->friend_request_push,
            'friend_request_telegram' => (bool) $this->friend_request_telegram,
            'daily_day_reminder_ui' => (bool) $this->daily_day_reminder_ui,
            'daily_day_reminder_email' => (bool) $this->daily_day_reminder_email,
            'daily_day_reminder_push' => (bool) $this->daily_day_reminder_push,
            'daily_day_reminder_telegram' => (bool) $this->daily_day_reminder_telegram,
            'daily_check_in_reminder_ui' => (bool) $this->daily_check_in_reminder_ui,
            'daily_check_in_reminder_email' => (bool) $this->daily_check_in_reminder_email,
            'daily_check_in_reminder_push' => (bool) $this->daily_check_in_reminder_push,
            'daily_check_in_reminder_telegram' => (bool) $this->daily_check_in_reminder_telegram,
            'calendar_share_ui' => (bool) $this->calendar_share_ui,
            'calendar_share_email' => (bool) $this->calendar_share_email,
            'calendar_share_push' => (bool) $this->calendar_share_push,
            'calendar_share_telegram' => (bool) $this->calendar_share_telegram,
            'dev_request_comment_ui' => (bool) $this->dev_request_comment_ui,
            'dev_request_comment_email' => (bool) $this->dev_request_comment_email,
            'dev_request_comment_push' => (bool) $this->dev_request_comment_push,
            'dev_request_comment_telegram' => (bool) $this->dev_request_comment_telegram,
            'export_ready_ui' => (bool) $this->export_ready_ui,
            'export_ready_email' => (bool) $this->export_ready_email,
            'export_ready_push' => (bool) $this->export_ready_push,
            'export_ready_telegram' => (bool) $this->export_ready_telegram,
        ];
    }
}
