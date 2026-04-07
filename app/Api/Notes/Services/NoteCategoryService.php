<?php

namespace App\Api\Notes\Services;

use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class NoteCategoryService
{
    private const string CACHE_KEY = 'note_categories';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function listRoots(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => NoteCategory::forUser($user->id)
                ->roots()
                ->with('descendants')
                ->orderBy('title', 'asc')
                ->get()
        );
    }

    public function find(NoteCategory $category): NoteCategory
    {
        return $category;
    }

    public function create(User $user, array $data): NoteCategory
    {
        $data['user_id'] = $user->id;
        $category = NoteCategory::create($data);
        $category->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $category;
    }

    public function update(NoteCategory $category, array $data): NoteCategory
    {
        $userId = $category->user_id;
        $category->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $category->fresh(['user']);
    }

    public function destroy(NoteCategory $category): void
    {
        $userId = $category->user_id;
        $category->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
