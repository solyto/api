<?php

namespace App\Api\TimeTracking\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use App\Api\TimeTracking\Factories\TimeTrackingCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimeTrackingCategory extends Model
{
    use HasFactory;

    protected static function newFactory(): TimeTrackingCategoryFactory
    {
        return TimeTrackingCategoryFactory::new();
    }

    protected $fillable = [
        'title',
        'color',
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            TimeTrackingProject::class,
            'time_tracking_project_category',
            'category_id',
            'project_id'
        );
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
