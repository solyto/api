<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryMovieFactory;
use App\Api\Tags\Models\Tag;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LibraryMovie extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): \App\Api\Libraries\Factories\LibraryMovieFactory
    {
        return LibraryMovieFactory::new();
    }

    protected $table = 'library_movies';

    protected $fillable = [
        'title',
        'rating',
        'publication_year',
        'category',
        'cover_path',
        'link',
        'wishlist',
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
        return $this->belongsToMany(Tag::class, 'tag_movie', 'movie_id', 'tag_id')->withTimestamps();
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(LibraryMovieGenre::class, 'library_genre_movie', 'movie_id', 'genre_id');
    }
}
