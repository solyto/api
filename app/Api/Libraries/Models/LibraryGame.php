<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryGameFactory;
use App\Api\Tags\Models\Tag;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LibraryGame extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): \App\Api\Libraries\Factories\LibraryGameFactory
    {
        return LibraryGameFactory::new();
    }

    protected $table = 'library_games';

    protected $fillable = [
        'title',
        'rating',
        'publication_year',
        'platform',
        'developer',
        'publisher',
        'playtime_hours',
        'completed',
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
        return $this->belongsToMany(Tag::class, 'tag_game', 'game_id', 'tag_id')->withTimestamps();
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(LibraryGameGenre::class, 'library_genre_game', 'game_id', 'genre_id');
    }
}
