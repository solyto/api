<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryYoutubeCategoryFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryYoutubeCategory extends Model
{
    use HasFactory;

    protected static function newFactory(): LibraryYoutubeCategoryFactory
    {
        return LibraryYoutubeCategoryFactory::new();
    }

    protected $table = 'library_youtube_categories';

    protected $fillable = [
        'title',
        'color',
        'sort_order',
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

    public function videos(): HasMany
    {
        return $this->hasMany(LibraryYoutubeVideo::class, 'category_id');
    }
}
