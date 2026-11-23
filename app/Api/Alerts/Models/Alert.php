<?php

namespace App\Api\Alerts\Models;

use App\Api\Alerts\Enums\AlertRule;
use App\Api\Alerts\Enums\AlertSource;
use App\Api\Alerts\Factories\AlertFactory;
use App\Api\CheckIn\Models\CheckInTracker;
use App\Api\Goals\Models\Goal;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use HasFactory;

    protected static function newFactory(): AlertFactory
    {
        return AlertFactory::new();
    }

    protected $fillable = [
        'user_id',
        'source',
        'check_in_tracker_id',
        'goal_id',
        'rule',
        'threshold',
        'days',
        'enabled',
        'suggestion',
        'is_triggered',
        'last_triggered_on',
    ];

    protected $casts = [
        'source' => AlertSource::class,
        'rule' => AlertRule::class,
        'threshold' => 'float',
        'days' => 'integer',
        'enabled' => 'boolean',
        'is_triggered' => 'boolean',
        'last_triggered_on' => 'date:Y-m-d',
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

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function scopeForUser(Builder $query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSwitchedOff(Builder $query)
    {
        $trackerOff = fn (Builder $tracker) => $tracker->where('enabled', false);

        return $query->where(fn (Builder $off) => $off
            ->where('enabled', false)
            ->orWhereHas('checkInTracker', $trackerOff)
            ->orWhereHas('goal.checkInTracker', $trackerOff));
    }
}
