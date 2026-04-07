<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryBookGenreFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryBookGenre extends Model
{
    use HasFactory;

    protected static function newFactory(): LibraryBookGenreFactory
    {
        return LibraryBookGenreFactory::new();
    }

    protected $table = 'library_books_genres';

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
