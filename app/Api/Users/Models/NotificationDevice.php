<?php

namespace App\Api\Users\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDevice extends Model
{
    public const int ACTIVE_DAYS = 30;

    protected $fillable = [
        'user_id',
        'device_id',
        'last_polled_at',
    ];

    protected $casts = [
        'last_polled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('last_polled_at', '>=', now()->subDays(self::ACTIVE_DAYS));
    }
}
