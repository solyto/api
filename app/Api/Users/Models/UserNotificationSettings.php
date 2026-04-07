<?php

namespace App\Api\Users\Models;

use App\Api\Users\Factories\UserNotificationSettingsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationSettings extends Model
{
    use HasFactory;

    protected static function newFactory(): UserNotificationSettingsFactory
    {
        return UserNotificationSettingsFactory::new();
    }

    protected $table = 'user_notification_settings';

    protected $fillable = [
        'user_id',
        'music_release_ui',
        'music_release_email',
        'music_release_push',
        'music_release_telegram',
        'book_release_ui',
        'book_release_email',
        'book_release_push',
        'book_release_telegram',
        'friend_request_ui',
        'friend_request_email',
        'friend_request_push',
        'friend_request_telegram',
        'daily_day_reminder_ui',
        'daily_day_reminder_email',
        'daily_day_reminder_push',
        'daily_day_reminder_telegram',
        'daily_check_in_reminder_ui',
        'daily_check_in_reminder_email',
        'daily_check_in_reminder_push',
        'daily_check_in_reminder_telegram',
        'calendar_share_ui',
        'calendar_share_email',
        'calendar_share_push',
        'calendar_share_telegram',
        'dev_request_comment_ui',
        'dev_request_comment_email',
        'dev_request_comment_push',
        'dev_request_comment_telegram',
    ];

    protected $casts = [
        'music_release_ui' => 'boolean',
        'music_release_email' => 'boolean',
        'music_release_push' => 'boolean',
        'music_release_telegram' => 'boolean',
        'book_release_ui' => 'boolean',
        'book_release_email' => 'boolean',
        'book_release_push' => 'boolean',
        'book_release_telegram' => 'boolean',
        'friend_request_ui' => 'boolean',
        'friend_request_email' => 'boolean',
        'friend_request_push' => 'boolean',
        'friend_request_telegram' => 'boolean',
        'daily_day_reminder_ui' => 'boolean',
        'daily_day_reminder_email' => 'boolean',
        'daily_day_reminder_push' => 'boolean',
        'daily_day_reminder_telegram' => 'boolean',
        'daily_check_in_reminder_ui' => 'boolean',
        'daily_check_in_reminder_email' => 'boolean',
        'daily_check_in_reminder_push' => 'boolean',
        'daily_check_in_reminder_telegram' => 'boolean',
        'calendar_share_ui' => 'boolean',
        'calendar_share_email' => 'boolean',
        'calendar_share_push' => 'boolean',
        'calendar_share_telegram' => 'boolean',
        'dev_request_comment_ui' => 'boolean',
        'dev_request_comment_email' => 'boolean',
        'dev_request_comment_push' => 'boolean',
        'dev_request_comment_telegram' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
