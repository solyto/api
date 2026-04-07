<?php

namespace App\Api\Feeds\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Api\Feeds\Factories\FeedSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeedSubscription extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): FeedSubscriptionFactory
    {
        return FeedSubscriptionFactory::new();
    }

    protected $fillable = [
        'title',
        'whitelist',
        'blacklist',
        'user_id',
        'feed_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
