<?php

namespace App\Shared\Models;

use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExportJob extends Model
{
    protected $table = 'export_jobs';

    protected $fillable = [
        'status',
        'features',
        'user_id',
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

    public function getFilePathAttribute(): string
    {
        return $this->user_id.'/export_'.$this->id.'.zip';
    }

    public function getExpiresAtAttribute(): ?string
    {
        if ($this->status !== 'completed') {
            return null;
        }

        return $this->created_at->addHours(48)->toIso8601String();
    }

    public function isExpired(): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        return $this->created_at->addHours(48)->isPast();
    }

    public function fileExists(): bool
    {
        return Storage::disk('user_data')->exists($this->file_path);
    }
}
