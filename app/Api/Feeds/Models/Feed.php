<?php

namespace App\Api\Feeds\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


use App\Api\Feeds\Factories\FeedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Feed extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): FeedFactory
    {
        return FeedFactory::new();
    }

    protected $table = 'feeds';

    protected $fillable = [
        'title',
        'url',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(FeedSubscription::class, 'feed_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeedItem::class, 'feed_id');
    }
}
