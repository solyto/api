<?php

namespace App\Api\Feeds\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Api\Feeds\Factories\FeedItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeedItem extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): FeedItemFactory
    {
        return FeedItemFactory::new();
    }

    protected $fillable = [
        'title',
        'link',
        'description',
        'image_url',
        'published_at',
        'feed_id',
        'feed_item_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }
}
