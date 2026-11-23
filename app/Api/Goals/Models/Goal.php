<?php

namespace App\Api\Goals\Models;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Models\Alert;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Goals\Enums\GoalComparison;
use App\Api\Goals\Enums\GoalPeriod;
use App\Api\Goals\Enums\GoalSource;
use App\Api\Goals\Factories\GoalFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Goal extends Model
{
    use HasFactory;

    protected static function newFactory(): GoalFactory
    {
        return GoalFactory::new();
    }

    protected $fillable = [
        'user_id',
        'source',
        'check_in_tracker_id',
        'period',
        'comparison',
        'target',
    ];

    protected $casts = [
        'source' => GoalSource::class,
        'period' => GoalPeriod::class,
        'comparison' => GoalComparison::class,
        'target' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CheckInTracker, $this> */
    public function checkInTracker(): BelongsTo
    {
        return $this->belongsTo(CheckInTracker::class);
    }

    /** @return HasOne<Alert, $this> */
    public function reachedAlert(): HasOne
    {
        return $this->hasOne(Alert::class)->where('rule', AlertRule::GoalReached->value);
    }

    public function scopeForUser(Builder $query, string $userId)
    {
        return $query->where('user_id', $userId);
    }
}
