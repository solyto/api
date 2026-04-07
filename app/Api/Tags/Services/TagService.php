<?php

namespace App\Api\Tags\Services;

use App\Api\Tags\Models\Tag;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class TagService
{
    private const string CACHE_KEY = 'tags';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => Tag::forUser($user->id)->get()
        );
    }

    public function find(Tag $tag): Tag
    {
        return $tag;
    }

    public function create(User $user, array $data): Tag
    {
        $data['user_id'] = $user->id;
        $tag = Tag::create($data);
        $tag->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $tag;
    }

    public function update(Tag $tag, array $data): Tag
    {
        $userId = $tag->user_id;
        $tag->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $tag->fresh(['user']);
    }

    public function destroy(Tag $tag): void
    {
        $userId = $tag->user_id;
        $tag->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
