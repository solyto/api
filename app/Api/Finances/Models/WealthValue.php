<?php

namespace App\Api\Finances\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Api\Finances\Factories\WealthValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WealthValue extends Model
{
    use HasFactory;

    protected static function newFactory(): WealthValueFactory
    {
        return WealthValueFactory::new();
    }

    protected $fillable = [
        'date',
        'value',
        'field_id',
    ];

    protected $casts = [
        'date' => 'date',
        'value' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function field(): BelongsTo
    {
        return $this->belongsTo(WealthField::class, 'field_id');
    }
}
