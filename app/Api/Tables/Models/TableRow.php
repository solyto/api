<?php

namespace App\Api\Tables\Models;

use App\Api\Tables\Factories\TableRowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableRow extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TableRowFactory
    {
        return TableRowFactory::new();
    }

    protected $fillable = [
        'data',
        'position',
        'table_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'data' => 'array',
        'position' => 'integer',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
}
