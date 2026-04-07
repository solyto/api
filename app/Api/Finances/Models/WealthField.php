<?php

namespace App\Api\Finances\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Api\Finances\Factories\WealthFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WealthField extends Model
{
    use HasFactory;

    protected static function newFactory(): WealthFieldFactory
    {
        return WealthFieldFactory::new();
    }

    protected $fillable = [
        'title',
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

    public function values(): HasMany
    {
        return $this->hasMany(WealthValue::class, 'field_id');
    }
}
