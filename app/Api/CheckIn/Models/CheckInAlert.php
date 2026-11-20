<?php

namespace App\Api\CheckIn\Models;

use App\Api\CheckIn\Factories\CheckInAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInAlert extends Model
{
    use HasFactory;

    protected static function newFactory(): CheckInAlertFactory
    {
        return CheckInAlertFactory::new();
    }

    protected $fillable = [
        'check_in_tracker_id',
        'time',
        'target',
        'last_sent_on',
    ];

    protected $casts = [
        'target' => 'integer',
        'last_sent_on' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<CheckInTracker, $this> */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(CheckInTracker::class, 'check_in_tracker_id');
    }
}
