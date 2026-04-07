<?php

namespace App\Api\Todos\Models;

use App\Api\Todos\Factories\TodoSubtaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TodoSubtask extends Model
{
    use HasFactory;

    protected static function newFactory(): TodoSubtaskFactory
    {
        return TodoSubtaskFactory::new();
    }

    protected $table = 'todo_subtasks';

    protected $fillable = [
        'title',
        'todo_id',
        'is_completed',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }
}
