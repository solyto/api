<?php

namespace App\Api\CheckIn\Models;

use App\Api\CheckIn\Enums\CheckInTrackerType;
use App\Api\CheckIn\Factories\CheckInTrackerFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckInTracker extends Model
{
    use HasFactory;

    protected static function newFactory(): CheckInTrackerFactory
    {
        return CheckInTrackerFactory::new();
    }

    protected $fillable = [
        'user_id',
        'key',
        'name',
        'type',
        'icon',
        'position',
        'enabled',
        'scored',
    ];

    protected $casts = [
        'type' => CheckInTrackerType::class,
        'position' => 'integer',
        'enabled' => 'boolean',
        'scored' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CheckInValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CheckInValue::class);
    }

    public function scopeForUser(Builder $query, string $userId)
    {
        return $query->where('user_id', $userId);
    }
}
