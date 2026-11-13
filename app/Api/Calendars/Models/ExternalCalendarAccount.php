<?php

namespace App\Api\Calendars\Models;

use App\Api\Calendars\Factories\ExternalCalendarAccountFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalCalendarAccount extends Model
{
    use HasFactory;

    protected static function newFactory(): ExternalCalendarAccountFactory
    {
        return ExternalCalendarAccountFactory::new();
    }

    protected $fillable = [
        'user_id',
        'server_url',
        'username',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ExternalCalendar, $this> */
    public function calendars(): HasMany
    {
        return $this->hasMany(ExternalCalendar::class);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
