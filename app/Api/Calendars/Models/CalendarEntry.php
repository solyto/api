<?php

namespace App\Api\Calendars\Models;

use App\Api\Calendars\Factories\CalendarEntryFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEntry extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): CalendarEntryFactory
    {
        return CalendarEntryFactory::new();
    }

    protected $table = 'calendar_entries';

    protected $fillable = [
        'calendar_id',
        'title',
        'description',
        'start_date',
        'end_date',
        'is_all_day',
        'recurrence_end',
        'recurrence_rule',
        'timezone',
        'location',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'start_date' => 'integer',
        'end_date' => 'integer',
        'is_all_day' => 'boolean',
        'recurrend_end' => 'integer',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
