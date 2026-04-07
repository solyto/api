<?php

namespace App\Api\DevRequests\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Api\DevRequests\Factories\DevRequestCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DevRequestComment extends Model
{
    use HasFactory;

    protected static function newFactory(): DevRequestCommentFactory
    {
        return DevRequestCommentFactory::new();
    }

    protected $fillable = [
        'dev_request_id',
        'user_id',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function devRequest(): BelongsTo
    {
        return $this->belongsTo(DevRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
