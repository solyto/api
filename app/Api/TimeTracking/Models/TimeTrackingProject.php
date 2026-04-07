<?php

namespace App\Api\TimeTracking\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Api\TimeTracking\Factories\TimeTrackingProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimeTrackingProject extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TimeTrackingProjectFactory
    {
        return TimeTrackingProjectFactory::new();
    }

    protected $fillable = [
        'title',
        'description',
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            TimeTrackingCategory::class,
            'time_tracking_project_category',
            'project_id',
            'category_id'
        );
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimeTrackingEntry::class, 'project_id');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
