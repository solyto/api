<?php

namespace App\Api\Tables\Factories;

use App\Api\Tables\Enums\TableColumnTypeEnum;
use App\Api\Tables\Models\Table;
use App\Api\Tables\Models\TableColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

class TableColumnFactory extends Factory
{
    protected $model = TableColumn::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'type' => TableColumnTypeEnum::TEXT->value,
            'options' => null,
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

    public function ofType(TableColumnTypeEnum $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type->value,
        ]);
    }
}
