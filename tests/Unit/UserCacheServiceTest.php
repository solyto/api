<?php

use App\Shared\Services\UserCacheService;

covers(UserCacheService::class);

describe('UserCacheService::remember', function () {
    it('returns the cached value without calling the callback on a hit', function () {
        $cache = new UserCacheService();
        $cache->store(['remember_hit', 'user-1'], 60, ['cached']);

        $calls = 0;
        $result = $cache->remember(['remember_hit', 'user-1'], 60, function () use (&$calls) {
            $calls++;

            return ['fresh'];
        });

        expect($result)->toBe(['cached']);
        expect($calls)->toBe(0);
    });

    it('computes and stores the value on a miss', function () {
        $cache = new UserCacheService();

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['fresh'];
        };

        expect($cache->remember(['remember_miss', 'user-1'], 60, $callback))->toBe(['fresh']);
        expect($cache->remember(['remember_miss', 'user-1'], 60, $callback))->toBe(['fresh']);
        expect($calls)->toBe(1);
    });

    it('treats a falsy cached value as a miss', function () {
        $cache = new UserCacheService();
        $cache->store(['remember_falsy', 'user-1'], 60, []);

        $result = $cache->remember(['remember_falsy', 'user-1'], 60, fn () => ['fresh']);

        expect($result)->toBe(['fresh']);
    });
});
