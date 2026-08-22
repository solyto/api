<?php

namespace App\Api\Tables\Services;

use App\Api\Tables\Models\Table;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class TableService
{
    private const string CACHE_KEY = 'tables';

    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn () => Table::forUser($user->id)->withCount('rows')->orderBy('position')->get()
        );
    }

    public function find(Table $table): Table
    {
        $table->load(['columns', 'rows']);

        return $table;
    }

    public function create(User $user, array $data): Table
    {
        $data['user_id'] = $user->id;
        $data['view'] = $data['view'] ?? 'list';
        $data['position'] = Table::forUser($user->id)->max('position') + 1;

        $table = Table::create($data);
        $table->load(['columns', 'rows']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $table;
    }

    public function update(Table $table, array $data): Table
    {
        $table->update($data);
        $table->load(['columns', 'rows']);

        $this->cache->forget([self::CACHE_KEY, $table->user_id]);

        return $table;
    }

    public function destroy(Table $table): void
    {
        $userId = $table->user_id;
        $table->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function reorder(User $user, array $ids): void
    {
        foreach (array_values($ids) as $position => $id) {
            Table::forUser($user->id)->where('id', $id)->update(['position' => $position]);
        }

        $this->cache->forget([self::CACHE_KEY, $user->id]);
    }
}
