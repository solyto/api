<?php

namespace App\Api\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceNotification extends Model
{
    public const int RETENTION_DAYS = 7;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'notification_id',
        'title',
        'body',
        'url',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
