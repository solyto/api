<?php

namespace App\Api\CheckIn\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CheckInService
{
    private const string CACHE_KEY = 'checkin';

    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user, ?string $from = null, ?string $to = null): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            function () use ($user) {
                return CheckIn::forUser($user->id)->get();
            }
        )
            ->filter(function (CheckIn $checkIn) use ($from, $to) {
                if ($from === null && $to === null) {
                    return true;
                }

                $date = $checkIn->date->toDateString();

                if ($from !== null && $date < $from) {
                    return false;
                }

                if ($to !== null && $date > $to) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    public function find(User $user, string $date): ?CheckIn
    {
        return CheckIn::forUser($user->id)->where('date', $date.' 00:00:00')->first();
    }

    /**
     * Dates (Y-m-d) within [$from, $to] that the user has a check-in for.
     *
     * @return array<int, string>
     */
    public function marks(User $user, string $from, string $to): array
    {
        return CheckIn::forUser($user->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all();
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
