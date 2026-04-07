<?php

namespace App\Api\Todos\Models;

use App\Api\Todos\Factories\TodoCategoryFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TodoCategory extends Model
{
    use HasFactory;

    protected static function newFactory(): TodoCategoryFactory
    {
        return TodoCategoryFactory::new();
    }

    protected $table = 'todo_categories';

    protected $fillable = [
        'title',
        'user_id',
    ];

    protected $casts = [
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
