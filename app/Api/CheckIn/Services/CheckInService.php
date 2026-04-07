<?php

namespace App\Api\CheckIn\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class CheckInService
{
    private const string CACHE_KEY = 'checkin';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            function () use ($user) {
                return CheckIn::forUser($user->id)->get();
            }
        );
    }

    public function find(User $user, string $date): ?CheckIn
    {
        return CheckIn::forUser($user->id)->where('date', $date . ' 00:00:00')->first();
    }

    public function create(User $user, array $data): CheckIn
    {
        $data['user_id'] = $user->id;
        $checkIn = CheckIn::create($data);
        $checkIn->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $checkIn;
    }

    public function update(User $user, CheckIn $checkIn, array $data): CheckIn
    {
        $checkIn->update($data);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $checkIn->fresh(['user']);
    }
}
