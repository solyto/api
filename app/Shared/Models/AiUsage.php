<?php

namespace App\Shared\Models;

use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsage extends Model
{
    protected $table = 'ai_usage';
    public $timestamps = false;

    protected $fillable = [
        'date',
        'feature',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'user_id',
    ];

    protected $casts = [
        'date' => 'datetime',
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
