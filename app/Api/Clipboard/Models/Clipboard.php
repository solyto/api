<?php

namespace App\Api\Clipboard\Models;

use App\Api\Clipboard\Factories\ClipboardFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clipboard extends Model
{
    use HasFactory;

    protected static function newFactory(): ClipboardFactory
    {
        return ClipboardFactory::new();
    }

    protected $fillable = [
        'content',
        'user_id',
        'type',
        'file_path',
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
