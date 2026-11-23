<?php

use App\Shared\Services\UserCacheService;
use Illuminate\Support\Facades\Log;

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

describe('UserCacheService::many', function () {
    it('returns hits by the caller\'s keys and null for misses and falsy values', function () {
        $cache = new UserCacheService();
        $cache->store(['many', 'a'], 60, ['a-value']);
        $cache->store(['many', 'falsy'], 60, []);

        $result = $cache->many([
            'first' => ['many', 'a'],
            'second' => ['many', 'missing'],
            'third' => ['many', 'falsy'],
        ]);

        expect($result)->toBe(['first' => ['a-value'], 'second' => null, 'third' => null]);
    });

    it('returns an empty array for no keys', function () {
        expect((new UserCacheService())->many([]))->toBe([]);
    });
});

describe('UserCacheService tagged entries', function () {
    it('remembers a tagged value and treats a falsy one as a miss', function () {
        $cache = new UserCacheService();
        $cache->storeTagged(['user_1'], ['tagged_falsy', 'user-1'], 60, []);

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['fresh'];
        };

        expect($cache->rememberTagged(['user_1'], ['tagged_falsy', 'user-1'], 60, $callback))->toBe(['fresh'])
            ->and($cache->rememberTagged(['user_1'], ['tagged_falsy', 'user-1'], 60, $callback))->toBe(['fresh'])
            ->and($calls)->toBe(1);
    });

    it('finds an entry only with the tags, in the order, it was stored with', function () {
        $cache = new UserCacheService();
        $cache->storeTagged(['user_1', 'calendar_1'], ['tagged', 'user-1'], 60, ['cached']);

        expect($cache->getTagged(['user_1', 'calendar_1'], ['tagged', 'user-1']))->toBe(['cached'])
            ->and($cache->getTagged(['calendar_1', 'user_1'], ['tagged', 'user-1']))->toBeNull()
            ->and($cache->getTagged(['user_1'], ['tagged', 'user-1']))->toBeNull()
            ->and($cache->get(['tagged', 'user-1']))->toBeNull();
    });

    it('keeps a tagged entry with a non-positive TTL forever', function () {
        $cache = new UserCacheService();
        $cache->storeTagged(['user_1'], ['tagged_forever', 'user-1'], 0, ['kept']);

        $this->travel(10)->years();

        expect($cache->getTagged(['user_1'], ['tagged_forever', 'user-1']))->toBe(['kept']);
    });

    it('forgets one tagged entry', function () {
        $cache = new UserCacheService();
        $cache->storeTagged(['user_1'], ['tagged_a', 'user-1'], 60, ['a']);
        $cache->storeTagged(['user_1'], ['tagged_b', 'user-1'], 60, ['b']);

        $cache->forgetTagged(['user_1'], ['tagged_a', 'user-1']);

        expect($cache->getTagged(['user_1'], ['tagged_a', 'user-1']))->toBeNull()
            ->and($cache->getTagged(['user_1'], ['tagged_b', 'user-1']))->toBe(['b']);
    });

    it('flushes every entry carrying one of the tags and nothing else', function () {
        $cache = new UserCacheService();
        $cache->storeTagged(['user_1', 'calendar_1'], ['flush', 'a'], 60, ['a']);
        $cache->storeTagged(['user_1', 'calendar_2'], ['flush', 'b'], 60, ['b']);
        $cache->storeTagged(['user_1'], ['flush', 'c'], 60, ['c']);
        $cache->storeTagged(['user_2', 'calendar_3'], ['flush', 'd'], 60, ['d']);
        $cache->store(['flush', 'e'], 60, ['e']);

        $cache->flushTags(['calendar_1']);

        expect($cache->getTagged(['user_1', 'calendar_1'], ['flush', 'a']))->toBeNull()
            ->and($cache->getTagged(['user_1', 'calendar_2'], ['flush', 'b']))->toBe(['b']);

        $cache->flushTags(['user_1']);

        expect($cache->getTagged(['user_1', 'calendar_2'], ['flush', 'b']))->toBeNull()
            ->and($cache->getTagged(['user_1'], ['flush', 'c']))->toBeNull()
            ->and($cache->getTagged(['user_2', 'calendar_3'], ['flush', 'd']))->toBe(['d'])
            ->and($cache->get(['flush', 'e']))->toBe(['e']);
    });

    it('logs a failed flush instead of throwing', function () {
        config(['cache.stores.untagged' => ['driver' => 'file', 'path' => storage_path('framework/testing/untagged-cache')]]);
        $log = Mockery::spy();
        Log::shouldReceive('channel')->with('cache')->andReturn($log);

        (new UserCacheService('untagged'))->flushTags(['user_1']);

        $log->shouldHaveReceived('warning')->with('Cache tag flush failed', Mockery::on(fn (array $context) => $context['tags'] === ['user_1']))->once();
    });

    it('builds the per-user tag', function () {
        expect(UserCacheService::userTag('user-1'))->toBe('user_user-1');
    });
});
