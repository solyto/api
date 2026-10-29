<?php

namespace App\Api\DevRequests\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Api\DevRequests\Factories\DevRequestVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DevRequestVote extends Model
{
    use HasFactory;

    protected static function newFactory(): DevRequestVoteFactory
    {
        return DevRequestVoteFactory::new();
    }

    protected $fillable = [
        'dev_request_id',
        'user_id',
        'vote_type',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<DevRequest, $this> */
    public function devRequest(): BelongsTo
    {
        return $this->belongsTo(DevRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
