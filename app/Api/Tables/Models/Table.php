<?php

namespace App\Api\Tables\Models;

use App\Api\Tables\Factories\TableFactory;
use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Table extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TableFactory
    {
        return TableFactory::new();
    }

    protected $fillable = [
        'name',
        'icon',
        'view',
        'position',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'position' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(TableColumn::class)->orderBy('position');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(TableRow::class)->orderBy('position');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
