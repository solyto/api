<?php

namespace App\Api\Todos\Services;

use App\Api\Todos\Models\TodoCategory;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class TodoCategoryService
{
    private const string CACHE_KEY = 'todo_categories';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => TodoCategory::forUser($user->id)->get()
        );
    }

    public function find(TodoCategory $category): TodoCategory
    {
        return $category;
    }

    public function create(User $user, array $data): TodoCategory
    {
        $data['user_id'] = $user->id;
        $category = TodoCategory::create($data);
        $category->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $category;
    }

    public function update(TodoCategory $category, array $data): TodoCategory
    {
        $userId = $category->user_id;
        $category->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $category->fresh(['user']);
    }

    public function destroy(TodoCategory $category): void
    {
        $userId = $category->user_id;
        $category->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
