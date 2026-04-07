<?php

namespace App\Api\Shortcuts\Services;

use App\Api\Shortcuts\Models\Shortcut;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class ShortcutService
{
    private const string CACHE_KEY = 'shortcuts';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => Shortcut::forUser($user->id)->orderBy('order', 'asc')->get()
        );
    }

    public function find(Shortcut $shortcut): Shortcut
    {
        return $shortcut;
    }

    public function create(User $user, array $data): Shortcut
    {
        $data['user_id'] = $user->id;
        $shortcut = Shortcut::create($data);
        $shortcut->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $shortcut;
    }

    public function update(Shortcut $shortcut, array $data): Shortcut
    {
        $userId = $shortcut->user_id;
        $shortcut->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $shortcut->fresh(['user']);
    }

    public function destroy(Shortcut $shortcut): void
    {
        $userId = $shortcut->user_id;
        $shortcut->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
