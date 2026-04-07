<?php

namespace App\Api\CheckIn\Models;

use App\Api\CheckIn\Factories\CheckInFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    use HasFactory;

    protected static function newFactory(): CheckInFactory
    {
        return CheckInFactory::new();
    }

    protected $table = 'check_in';

    protected $fillable = [
        'date',
        'mood',
        'water',
        'sports',
        'sleep',
        'dreams',
        'work',
        'food_quality',
        'food_amount',
        'menstruation',
        'alcohol',
        'smoking',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
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
}
