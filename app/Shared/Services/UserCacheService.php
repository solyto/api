<?php

namespace App\Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;

class UserCacheService
{
    private const string STORE_NAME = 'user_data';

    public function store(array $identifiers, int $ttl, $data): bool
    {
        return Cache::store(self::STORE_NAME)->put($this->getKey($identifiers), $data, $ttl);
    }

    public function get(array $identifiers): mixed
    {
        try {
            $item = Cache::store(self::STORE_NAME)->get($this->getKey($identifiers));

            if (!$item) {
                return null;
            }

            return $item;
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache get failed', ['key' => $this->getKey($identifiers), 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function has(array $identifiers): bool
    {
        try {
            return Cache::store(self::STORE_NAME)->has($this->getKey($identifiers));
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache has failed', ['key' => $this->getKey($identifiers), 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function remember(array $identifiers, int $ttl, callable $callback): mixed
    {
        if ($this->has($identifiers)) {
            $result = $this->get($identifiers);

            if ($result !== null) {
                return $this->get($identifiers);
            }
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
            Cache::store(self::STORE_NAME)->delete($this->getKey($identifiers));
        } catch (InvalidArgumentException $e) {
            Log::channel('cache')->warning('Cache delete failed', ['key' => $this->getKey($identifiers), 'error' => $e->getMessage()]);
        }
    }

    public function forgetByPrefix(array $identifiers): void
    {
        try {
            $store = Cache::store(self::STORE_NAME);
            $prefix = $store->getPrefix() . $this->getKey($identifiers) . '_';
            $redis = $store->connection();
            $keys = $redis->keys($prefix . '*');

            if (!empty($keys)) {
                $redisPrefix = $redis->getOption(\Redis::OPT_PREFIX) ?? '';
                if ($redisPrefix !== '') {
                    $keys = array_map(fn($k) => substr($k, strlen($redisPrefix)), $keys);
                }
                $redis->del($keys);
            }
        } catch (\Exception $e) {
            Log::channel('cache')->warning('Cache prefix delete failed', ['prefix' => $this->getKey($identifiers), 'error' => $e->getMessage()]);
        }
    }

    private function getKey(array $identifiers): string
    {
        return implode('_', $identifiers);
    }
}
