<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\AuthorFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    use HasFactory;

    protected static function newFactory(): AuthorFactory
    {
        return AuthorFactory::new();
    }

    protected $table = 'authors';

    protected $fillable = [
        'name',
        'photo',
        'bio',
        'hardcover_id',
        'is_favorite',
        'user_id',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
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

    public function books(): HasMany
    {
        return $this->hasMany(LibraryBook::class, 'author_id');
    }
}
