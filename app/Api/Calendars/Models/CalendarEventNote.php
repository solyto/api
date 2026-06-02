<?php

namespace App\Api\Calendars\Models;

use App\Api\Notes\Models\Note;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventNote extends Model
{
    protected $table = 'calendar_event_notes';

    protected $fillable = [
        'calendar_object_id',
        'note_id',
        'user_id',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForEvent($query, int $calendarObjectId)
    {
        return $query->where('calendar_object_id', $calendarObjectId);
    }
}
