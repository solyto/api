<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryMusicFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LibraryMusic extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): \App\Api\Libraries\Factories\LibraryMusicFactory
    {
        return LibraryMusicFactory::new();
    }

    protected $table = 'library_music';

    protected $fillable = [
        'title',
        'artist',
        'type',
        'format',
        'condition',
        'rating',
        'publication_year',
        'acquired_where',
        'additional_info',
        'cover_path',
        'wishlist',
        'link',
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

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(LibraryMusicGenre::class, 'library_genre_music', 'music_id', 'genre_id');
    }
}
