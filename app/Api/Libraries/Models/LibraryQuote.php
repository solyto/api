<?php

namespace App\Api\Libraries\Models;

use App\Api\Libraries\Factories\LibraryQuoteFactory;
use App\Api\Tags\Models\Tag;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LibraryQuote extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): \App\Api\Libraries\Factories\LibraryQuoteFactory
    {
        return LibraryQuoteFactory::new();
    }

    protected $table = 'library_quotes';

    protected $fillable = [
        'summary',
        'author',
        'quote',
        'source',
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

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_quote', 'quote_id', 'tag_id')->withTimestamps();
    }
}
