<?php

namespace App\Api\Planner\Models;

use App\Api\Planner\Factories\PlannerEntryFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannerEntry extends Model
{
    use HasFactory;

    protected static function newFactory(): PlannerEntryFactory
    {
        return PlannerEntryFactory::new();
    }

    protected $fillable = [
        'user_id',
        'date',
        'title',
        'start_minutes',
        'duration_minutes',
        'is_all_day',
        'color',
        'todo_id',
        'sort_order',
    ];

    protected $casts = [
        'date' => 'date',
        'start_minutes' => 'integer',
        'duration_minutes' => 'integer',
        'is_all_day' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
