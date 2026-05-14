<?php

namespace App\Api\DevRequests\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


use App\Api\DevRequests\Factories\DevRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DevRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'title',
        'description',
        'screenshot',
        'url',
        'priority',
        'created_by_user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function newFactory(): DevRequestFactory
    {
        return DevRequestFactory::new();
    }

    public function votes(): HasMany
    {
        return $this->hasMany(DevRequestVote::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DevRequestComment::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
