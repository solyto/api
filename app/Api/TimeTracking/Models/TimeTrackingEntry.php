<?php

namespace App\Api\TimeTracking\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


use App\Api\TimeTracking\Factories\TimeTrackingEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimeTrackingEntry extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TimeTrackingEntryFactory
    {
        return TimeTrackingEntryFactory::new();
    }

    protected $fillable = [
        'description',
        'started_at',
        'stopped_at',
        'duration_minutes',
        'has_exact_times',
        'project_id',
        'user_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'duration_minutes' => 'integer',
        'has_exact_times' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(TimeTrackingProject::class, 'project_id');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
