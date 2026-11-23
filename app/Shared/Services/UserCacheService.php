<?php

namespace App\Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;

class UserCacheService
{
    private const string STORE_NAME = 'user_data';

    public function __construct(private readonly string $storeName = self::STORE_NAME) {}

    public function store(array $identifiers, int $ttl, $data): bool
    {
        $store = Cache::store($this->storeName);

        // A non-positive TTL means "keep forever" (Laravel's Repository::put
        // would otherwise delete the key), used for long-lived dedup state.
        if ($ttl <= 0) {
            return $store->forever($this->getKey($identifiers), $data);
        }

        return $store->put($this->getKey($identifiers), $data, $ttl);
    }

    public function get(array $identifiers): mixed
    {
        try {
            $item = Cache::store($this->storeName)->get($this->getKey($identifiers));

            if (! $item) {
                return null;
            }

            return $item;
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache get failed', ['key' => $this->getKey($identifiers), 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * One round trip for several keys. Results keep the keys of
     * $identifierLists; misses and falsy values are null, like get().
     *
     * @param  array<array-key, array<int, string>>  $identifierLists
     * @return array<array-key, mixed>
     */
    public function many(array $identifierLists): array
    {
        $keys = array_map(fn (array $identifiers) => $this->getKey($identifiers), $identifierLists);

        if ($keys === []) {
            return [];
        }

        try {
            $values = Cache::store($this->storeName)->many(array_values($keys));
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache many failed', ['keys' => array_values($keys), 'error' => $e->getMessage()]);

            return array_fill_keys(array_keys($keys), null);
        }

        return array_map(fn (string $key) => ($values[$key] ?? null) ?: null, $keys);
    }

    public function has(array $identifiers): bool
    {
        try {
            return Cache::store($this->storeName)->has($this->getKey($identifiers));
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache has failed', ['key' => $this->getKey($identifiers), 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function remember(array $identifiers, int $ttl, callable $callback): mixed
    {
        $result = $this->get($identifiers);

        if ($result !== null) {
            return $result;
        }

        $results = $callback();

        $this->store($identifiers, $ttl, $results);

        return $results;
    }

    public function replace(array $identifiers, int $ttl, $data): void
    {
        $this->forget($identifiers);
        $this->store($identifiers, $ttl, $data);
    }

    public function forget(array $identifiers): void
    {
        try {
            Cache::store($this->storeName)->delete($this->getKey($identifiers));
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache delete failed', ['key' => $this->getKey($identifiers), 'error' => $e->getMessage()]);
        }
    }

    /**
     * The tag every tagged per-user entry carries, so one flush drops all of
     * a user's tagged entries.
     */
    public static function userTag(string $userId): string
    {
        return 'user_'.$userId;
    }

    /**
     * Tagged entries are only found with the tags, in the order, they were
     * stored with.
     *
     * @param  array<int, string>  $tags
     */
    public function storeTagged(array $tags, array $identifiers, int $ttl, $data): bool
    {
        $store = Cache::store($this->storeName)->tags($tags);

        // A non-positive TTL means "keep forever", as in store().
        if ($ttl <= 0) {
            return $store->forever($this->getKey($identifiers), $data);
        }

        return $store->put($this->getKey($identifiers), $data, $ttl);
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function getTagged(array $tags, array $identifiers): mixed
    {
        try {
            return Cache::store($this->storeName)->tags($tags)->get($this->getKey($identifiers)) ?: null;
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache get failed', ['key' => $this->getKey($identifiers), 'tags' => $tags, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function rememberTagged(array $tags, array $identifiers, int $ttl, callable $callback): mixed
    {
        $result = $this->getTagged($tags, $identifiers);

        if ($result !== null) {
            return $result;
        }

        $results = $callback();

        $this->storeTagged($tags, $identifiers, $ttl, $results);

        return $results;
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function forgetTagged(array $tags, array $identifiers): void
    {
        try {
            Cache::store($this->storeName)->tags($tags)->forget($this->getKey($identifiers));
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache delete failed', ['key' => $this->getKey($identifiers), 'tags' => $tags, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Drops every entry stored with any of the tags. Runs after writes, so a
     * cache failure is logged and never fails the write.
     *
     * @param  array<int, string>  $tags
     */
    public function flushTags(array $tags): void
    {
        try {
            Cache::store($this->storeName)->tags($tags)->flush();
        } catch (\Throwable $e) {
            Log::channel('cache')->warning('Cache tag flush failed', ['tags' => $tags, 'error' => $e->getMessage()]);
        }
    }

    private function getKey(array $identifiers): string
    {
        return implode('_', $identifiers);
    }
}
