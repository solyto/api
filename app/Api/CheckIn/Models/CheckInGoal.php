<?php

namespace App\Api\CheckIn\Models;

use App\Api\CheckIn\Enums\CheckInGoalComparison;
use App\Api\CheckIn\Enums\CheckInGoalPeriod;
use App\Api\CheckIn\Factories\CheckInGoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInGoal extends Model
{
    use HasFactory;

    protected static function newFactory(): CheckInGoalFactory
    {
        return CheckInGoalFactory::new();
    }

    protected $fillable = [
        'check_in_tracker_id',
        'period',
        'comparison',
        'target',
    ];

    protected $casts = [
        'period' => CheckInGoalPeriod::class,
        'comparison' => CheckInGoalComparison::class,
        'target' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<CheckInTracker, $this> */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(CheckInTracker::class, 'check_in_tracker_id');
    }
}
