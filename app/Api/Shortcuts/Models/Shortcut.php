<?php

namespace App\Api\Shortcuts\Models;

use App\Api\Shortcuts\Factories\ShortcutFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shortcut extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): ShortcutFactory
    {
        return ShortcutFactory::new();
    }

    protected $table = 'shortcuts';

    protected $fillable = [
        'title',
        'url',
        'order',
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
