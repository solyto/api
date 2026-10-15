<?php

namespace App\Api\Finances\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Api\Finances\Factories\BudgetValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BudgetValue extends Model
{
    use HasFactory;

    protected static function newFactory(): BudgetValueFactory
    {
        return BudgetValueFactory::new();
    }

    protected $fillable = [
        'date',
        'value',
        'budget_id',
    ];

    protected $casts = [
        'date' => 'date',
        'value' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }
}
