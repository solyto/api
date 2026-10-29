<?php

namespace App\Api\Todos\Models;

use App\Api\Todos\Factories\TodoWorkspaceFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TodoWorkspace extends Model
{
    use HasFactory;

    protected static function newFactory(): TodoWorkspaceFactory
    {
        return TodoWorkspaceFactory::new();
    }

    protected $fillable = [
        'title',
        'user_id',
        'is_hideable',
    ];

    protected $casts = [
        'is_hideable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /** @return BelongsToMany<TodoCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TodoCategory::class, 'workspace_category', 'workspace_id', 'category_id');
    }
}
