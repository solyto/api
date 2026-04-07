<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryLinkCategoryFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryLinkCategory extends Model
{
    use HasFactory;

    protected static function newFactory(): LibraryLinkCategoryFactory
    {
        return LibraryLinkCategoryFactory::new();
    }

    protected $table = 'library_links_categories';

    protected $fillable = [
        'title',
        'color',
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

    public function links(): HasMany
    {
        return $this->hasMany(LibraryLink::class, 'category_id');
    }
}
