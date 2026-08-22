<?php

namespace App\Api\Tables\Factories;

use App\Api\Tables\Models\Table;
use App\Api\Tables\Models\TableRow;
use Illuminate\Database\Eloquent\Factories\Factory;

class TableRowFactory extends Factory
{
    protected $model = TableRow::class;

    public function definition(): array
    {
        return [
            'data' => [],
            'position' => 0,
            'table_id' => Table::factory(),
        ];
    }

    public function forTable(Table $table): static
    {
        return $this->state(fn (array $attributes) => [
            'table_id' => $table->id,
        ]);
    }

    public function withData(array $data): static
    {
        return $this->state(fn (array $attributes) => [
            'data' => $data,
        ]);
    }
}
