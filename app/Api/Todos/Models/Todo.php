<?php

namespace App\Api\Todos\Models;

use App\Api\Tags\Models\Tag;
use App\Api\Todos\Factories\TodoFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Todo extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TodoFactory
    {
        return TodoFactory::new();
    }

    protected $fillable = [
        'title',
        'description',
        'link',
        'is_completed',
        'priority',
        'due_at',
        'user_id',
        'category_id',
        'effort',
        'progress',
        'status',
        'completed_at',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_ends_at',
        'parent_task_id',
        'auto_generated',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'auto_generated' => 'boolean',
        'due_at' => 'date',
        'recurrence_ends_at' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TodoCategory::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withTimestamps();
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(TodoSubtask::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Todo::class, 'parent_task_id');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
