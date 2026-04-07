<?php

namespace App\Api\Users\Models;

use App\Api\Users\Factories\UserProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    protected static function newFactory(): UserProfileFactory
    {
        return UserProfileFactory::new();
    }

    protected $fillable = [
        'user_id',
        'profile_image_path',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
