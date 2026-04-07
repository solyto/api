<?php

namespace App\Api\Calendars\Models;

use App\Api\Calendars\Factories\CalendarFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Calendar extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): CalendarFactory
    {
        return CalendarFactory::new();
    }

    protected $fillable = [
        'user_id',
        'title',
        'is_active',
        'color',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CalendarEntry::class);
    }
}
