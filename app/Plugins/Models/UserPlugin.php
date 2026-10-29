<?php

namespace App\Plugins\Models;

use App\Api\Users\Models\User;
use App\Plugins\Enums\PluginEnum;
use App\Plugins\Factories\UserPluginFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPlugin extends Model
{
    use HasFactory;

    protected static function newFactory(): UserPluginFactory
    {
        return UserPluginFactory::new();
    }

    protected $fillable = [
        'user_id',
        'plugin',
        'enabled',
        'credentials',
        'settings',
    ];

    protected $casts = [
        'plugin' => PluginEnum::class,
        'enabled' => 'boolean',
        'credentials' => 'encrypted:array',
        'settings' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
