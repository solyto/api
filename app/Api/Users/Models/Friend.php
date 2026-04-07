<?php

namespace App\Api\Users\Models;

use App\Api\Users\Factories\FriendFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friend extends Model
{
    use HasFactory;

    protected static function newFactory(): FriendFactory
    {
        return FriendFactory::new();
    }

    public $timestamps = false;

    protected $fillable = [
        'user_id_1',
        'user_id_2',
        'friends_since',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'friends_since' => 'datetime',
    ];

    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_1');
    }

    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_2');
    }
}
