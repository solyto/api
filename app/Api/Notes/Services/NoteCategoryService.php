<?php

namespace App\Api\Notes\Services;

use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class NoteCategoryService
{
    private const string CACHE_KEY = 'note_categories';
    private const string CACHE_KEY_HIDDEN = 'note_categories_hidden';
    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly UserCacheService $cache,
        private readonly NoteService $noteService,
    ) {}

    public function listRoots(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => $this->tree($user->id, false)
        );
    }

    // Rooted at the topmost hidden or system categories.
    public function listHidden(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_HIDDEN, $user->id],
            self::CACHE_TTL,
            fn() => $this->tree($user->id, true)
        );
    }

    public function create(User $user, array $data): NoteCategory
    {
        $data['user_id'] = $user->id;
        $category = NoteCategory::create($data);
        $category->load(['user']);

        $this->forgetUser($user->id);

        return $category;
    }

    public function update(NoteCategory $category, array $data): NoteCategory
    {
        $userId = $category->user_id;
        $category->update($data);

        $this->forgetUser($userId);

        return $category->fresh(['user']);
    }

    public function destroy(NoteCategory $category): void
    {
        $userId = $category->user_id;
        $category->delete();

        $this->forgetUser($userId);
    }

    public function updateHidden(NoteCategory $category, bool $hidden): NoteCategory
    {
        $userId = $category->user_id;
        $category->update(['is_hidden' => $hidden]);

        $this->forgetUser($userId);

        return $category->fresh(['user']);
    }

    // One flat load instead of with('descendants'): a constraint there would only filter the first level.
    // Roots are ordered by title, children by sort_order, as the descendants relation does.
    private function tree(string $userId, bool $hidden): Collection
    {
        $hiddenIds = array_flip($this->noteService->hiddenCategoryIds($userId));

        $nodes = NoteCategory::forUser($userId)
            ->orderBy('title', 'asc')
            ->get()
            ->filter(fn(NoteCategory $category) => isset($hiddenIds[$category->id]) === $hidden)
            ->keyBy('id');

        $children = $nodes->groupBy('parent_id');

        foreach ($nodes as $node) {
            $node->setRelation('descendants', new EloquentCollection(
                $children->get($node->id, collect())->sortBy('sort_order')->values()->all()
            ));
        }

        return $nodes
            ->filter(fn(NoteCategory $category) => $category->parent_id === null || !$nodes->has($category->parent_id))
            ->values();
    }

    // Category writes change which notes are hidden, so the note lists go too.
    public function forgetUser(string $userId): void
    {
        $this->cache->forget([self::CACHE_KEY, $userId]);
        $this->cache->forget([self::CACHE_KEY_HIDDEN, $userId]);
        $this->noteService->forgetUser($userId);
    }
}
