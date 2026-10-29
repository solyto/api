<?php

namespace App\Api\Calendars\Models;

use App\Api\Todos\Models\Todo;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventTodo extends Model
{
    protected $table = 'calendar_event_todos';

    protected $fillable = [
        'calendar_object_id',
        'todo_id',
        'user_id',
    ];

    /** @return BelongsTo<Todo, $this> */
    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForEvent($query, string $calendarObjectId)
    {
        return $query->where('calendar_object_id', $calendarObjectId);
    }
}
