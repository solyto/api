<?php

namespace App\Api\Telegram\Models;

use App\Api\Telegram\Factories\TelegramBotConnectionFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBotConnection extends Model
{
    use HasFactory;

    protected static function newFactory(): TelegramBotConnectionFactory
    {
        return TelegramBotConnectionFactory::new();
    }

    protected $fillable = [
        'token',
        'is_confirmed',
        'chat_id',
        'user_id',
        'your_day_alert',
        'check_in_alert',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_confirmed' => 'boolean',
        'your_day_alert' => 'boolean',
        'check_in_alert' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForChatId($query, $chatId)
    {
        return $query->where('chat_id', $chatId);
    }
}
