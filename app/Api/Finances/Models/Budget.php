<?php

namespace App\Api\Finances\Models;


use App\Api\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


use App\Api\Finances\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Budget extends Model
{
    use HasFactory;

    protected static function newFactory(): BudgetFactory
    {
        return BudgetFactory::new();
    }

    protected $fillable = [
        'title',
        'type',
        'value',
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
}
