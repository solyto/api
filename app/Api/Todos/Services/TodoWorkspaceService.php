<?php

namespace App\Api\Todos\Services;

use App\Api\Todos\Models\TodoWorkspace;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class TodoWorkspaceService
{
    private const string CACHE_KEY = 'todo_workspaces';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => TodoWorkspace::forUser($user->id)->with('categories')->get()
        );
    }

    public function find(TodoWorkspace $workspace): TodoWorkspace
    {
        $workspace->load(['categories']);

        return $workspace;
    }

    public function create(User $user, array $data): TodoWorkspace
    {
        $data['user_id'] = $user->id;
        $workspace = TodoWorkspace::create($data);

        if (isset($data['categories'])) {
            $workspace->categories()->attach($data['categories']);
        }

        $workspace->load(['user', 'categories']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $workspace;
    }

    public function update(TodoWorkspace $workspace, array $data): TodoWorkspace
    {
        $userId = $workspace->user_id;
        $workspace->update($data);
        $workspace->load(['user', 'categories']);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $workspace;
    }

    public function destroy(TodoWorkspace $workspace): void
    {
        $userId = $workspace->user_id;
        $workspace->categories()->detach();
        $workspace->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function attachCategories(TodoWorkspace $workspace, array $categoryIds): TodoWorkspace
    {
        $workspace->categories()->attach($categoryIds);
        $workspace->load('categories');

        $this->cache->forget([self::CACHE_KEY, $workspace->user_id]);

        return $workspace;
    }

    public function detachCategories(TodoWorkspace $workspace, array $categoryIds): TodoWorkspace
    {
        $workspace->categories()->detach($categoryIds);
        $workspace->load('categories');

        $this->cache->forget([self::CACHE_KEY, $workspace->user_id]);

        return $workspace;
    }
}
