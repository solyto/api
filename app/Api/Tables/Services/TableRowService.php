<?php

namespace App\Api\Tables\Services;

use App\Api\Tables\Models\Table;
use App\Api\Tables\Models\TableRow;
use App\Shared\Services\UserCacheService;

class TableRowService
{
    private const string CACHE_KEY = 'tables';

    public function __construct(private readonly UserCacheService $cache) {}

    public function create(Table $table, array $data): TableRow
    {
        $row = TableRow::create([
            'table_id' => $table->id,
            'data' => $this->sanitizeData($table, $data['data'] ?? []),
            'position' => $table->rows()->max('position') + 1,
        ]);

        $this->cache->forget([self::CACHE_KEY, $table->user_id]);

        return $row;
    }

    public function update(TableRow $row, array $data): TableRow
    {
        $payload = [];

        if (array_key_exists('data', $data)) {
            $payload['data'] = $this->sanitizeData($row->table, $data['data'] ?? []);
        }

        $row->update($payload);

        return $row->fresh();
    }

    public function destroy(TableRow $row): void
    {
        $userId = $row->table->user_id;
        $row->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function reorder(Table $table, array $ids): void
    {
        foreach (array_values($ids) as $position => $id) {
            $table->rows()->where('id', $id)->update(['position' => $position]);
        }
    }

    /**
     * Only keep values for columns that actually exist on the table.
     */
    private function sanitizeData(Table $table, array $data): array
    {
        $columnIds = $table->columns()->pluck('id')->all();

        return array_intersect_key($data, array_flip($columnIds));
    }
}
