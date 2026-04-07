<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryLinkService
{
    private const string CACHE_KEY = 'links';
    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly UserCacheService $cache,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => LibraryLink::forUser($user->id)->with(['tags', 'category'])->orderBy('created_at', 'desc')->get()
        );
    }

    public function find(LibraryLink $link): LibraryLink
    {
        $link->load(['tags', 'category']);

        return $link;
    }

    public function newest(User $user): Collection
    {
        return LibraryLink::forUser($user->id)->orderBy('created_at', 'desc')->limit(5)->get();
    }

    public function create(User $user, array $data): LibraryLink
    {
        $data['user_id'] = $user->id;

        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($data['user_id'], $data['cover_path'], LibraryTypeEnum::BOOK);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $link = LibraryLink::create($data);

        if (isset($data['tags'])) {
            $link->tags()->attach($data['tags']);
        }

        $link->load(['user', 'tags', 'category']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $link;
    }

    public function update(LibraryLink $link, array $data): LibraryLink
    {
        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($link->user_id, $data['cover_path'], LibraryTypeEnum::BOOK);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $link->update($data);

        if (array_key_exists('tags', $data)) {
            $link->tags()->sync($data['tags']);
        }

        $link->load(['user', 'tags', 'category']);

        $this->cache->forget([self::CACHE_KEY, $link->user_id]);

        return $link;
    }

    public function destroy(LibraryLink $link): void
    {
        $userId = $link->user_id;
        $link->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function listCategories(User $user): Collection
    {
        return LibraryLinkCategory::forUser($user->id)->orderBy('title', 'asc')->get();
    }

    public function createCategory(User $user, array $data): LibraryLinkCategory
    {
        $data['user_id'] = $user->id;
        $category = LibraryLinkCategory::create($data);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $category;
    }

    public function updateCategory(LibraryLinkCategory $category, array $data): LibraryLinkCategory
    {
        $userId = $category->user_id;
        $category->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $category;
    }

    public function destroyCategory(LibraryLinkCategory $category): void
    {
        $userId = $category->user_id;
        $category->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
