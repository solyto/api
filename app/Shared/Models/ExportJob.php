<?php

namespace App\Shared\Models;

use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJob extends Model
{
    protected $table = 'export_jobs';

    protected $fillable = [
        'status',
        'features',
        'user_id'
    ];

    protected $casts = [
        'features' => 'json',
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
