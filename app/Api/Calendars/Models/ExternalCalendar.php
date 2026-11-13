<?php

namespace App\Api\Calendars\Models;

use App\Api\Calendars\Factories\ExternalCalendarFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCalendar extends Model
{
    use HasFactory;

    protected static function newFactory(): ExternalCalendarFactory
    {
        return ExternalCalendarFactory::new();
    }

    protected $fillable = [
        'external_calendar_account_id',
        'user_id',
        'remote_url',
        'calendar_id',
        'instance_id',
    ];

    protected $casts = [
        'calendar_id' => 'integer',
        'instance_id' => 'integer',
    ];

    /** @return BelongsTo<ExternalCalendarAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ExternalCalendarAccount::class, 'external_calendar_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
