<?php

namespace App\Api\Tables\Models;

use App\Api\Tables\Enums\TableColumnTypeEnum;
use App\Api\Tables\Factories\TableColumnFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableColumn extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TableColumnFactory
    {
        return TableColumnFactory::new();
    }

    protected $fillable = [
        'name',
        'type',
        'options',
        'position',
        'table_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'type' => TableColumnTypeEnum::class,
        'options' => 'array',
        'position' => 'integer',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
}
