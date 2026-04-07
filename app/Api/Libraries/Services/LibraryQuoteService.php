<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryQuoteService
{
    private const string CACHE_KEY = 'quotes';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => LibraryQuote::forUser($user->id)->orderBy('created_at', 'desc')->with(['tags'])->get()
        );
    }

    public function find(LibraryQuote $quote): LibraryQuote
    {
        $quote->load(['tags']);

        return $quote;
    }

    public function random(User $user): ?LibraryQuote
    {
        return LibraryQuote::forUser($user->id)->inRandomOrder()->first();
    }

    public function create(User $user, array $data): LibraryQuote
    {
        $data['user_id'] = $user->id;
        $quote = LibraryQuote::create($data);

        if (isset($data['tags'])) {
            $quote->tags()->attach($data['tags']);
        }

        $quote->load(['user', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $quote;
    }

    public function update(LibraryQuote $quote, array $data): LibraryQuote
    {
        $quote->update($data);

        if (array_key_exists('tags', $data)) {
            $quote->tags()->sync($data['tags']);
        }

        $quote->load(['user', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $quote->user_id]);

        return $quote;
    }

    public function destroy(LibraryQuote $quote): void
    {
        $userId = $quote->user_id;
        $quote->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
