<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryBookFactory;
use App\Api\Tags\Models\Tag;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LibraryBook extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): LibraryBookFactory
    {
        return LibraryBookFactory::new();
    }

    protected $table = 'library_books';

    protected $fillable = [
        'title',
        'author',
        'series',
        'volume',
        'rating',
        'publication_year',
        'pages',
        'current_page',
        'lent_to',
        'is_where',
        'cover_path',
        'link',
        'wishlist',
        'summary',
        'user_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'started_at' => 'date',
        'finished_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_book', 'book_id', 'tag_id')->withTimestamps();
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(LibraryBookGenre::class, 'library_genre_book', 'book_id', 'genre_id');
    }
}
