<?php

namespace App\Api\Tables\Services;

use App\Api\Tables\Models\Table;
use App\Api\Tables\Models\TableColumn;

class TableColumnService
{
    public function create(Table $table, array $data): TableColumn
    {
        $data['table_id'] = $table->id;
        $data['position'] = $table->columns()->max('position') + 1;

        return TableColumn::create($data);
    }

    public function update(TableColumn $column, array $data): TableColumn
    {
        $column->update($data);

        return $column->fresh();
    }

    public function destroy(TableColumn $column): void
    {
        $table = $column->table;
        $columnId = $column->id;

        $column->delete();

        // Remove the column's values from every row's data so nothing dangles.
        foreach ($table->rows as $row) {
            if (array_key_exists($columnId, $row->data ?? [])) {
                $data = $row->data;
                unset($data[$columnId]);
                $row->update(['data' => $data]);
            }
        }
    }

    public function reorder(Table $table, array $ids): void
    {
        foreach (array_values($ids) as $position => $id) {
            $table->columns()->where('id', $id)->update(['position' => $position]);
        }
    }
}
