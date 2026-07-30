<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryVideoFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryVideo extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): LibraryVideoFactory
    {
        return LibraryVideoFactory::new();
    }

    protected $table = 'library_videos';

    protected $fillable = [
        'title',
        'video_id',
        'url',
        'cover_path',
        'is_favorite',
        'sort_order',
        'category_id',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(LibraryVideoCategory::class, 'category_id');
    }
}
