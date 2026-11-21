<?php

namespace App\Api\CheckIn\Models;

use App\Api\CheckIn\Factories\CheckInValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInValue extends Model
{
    use HasFactory;

    protected static function newFactory(): CheckInValueFactory
    {
        return CheckInValueFactory::new();
    }

    protected $fillable = [
        'check_in_tracker_id',
        'date',
        'value',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'value' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<CheckInTracker, $this> */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(CheckInTracker::class, 'check_in_tracker_id');
    }
}
