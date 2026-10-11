<?php

namespace App\Api\Planner\Services;

use App\Api\Planner\Models\PlannerEntry;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class PlannerEntryService
{
    private const string CACHE_KEY = 'planner_entries';

    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    /**
     * Entries for the user, optionally scoped to an inclusive date range,
     * ordered by date, then start_minutes, then sort_order.
     *
     * The user's full set is cached under a single per-user key (mirroring
     * TagService/CheckInService); writes invalidate it by exact key so no
     * ranged/cross-key bookkeeping is needed.
     */
    public function list(User $user, string $from, string $to): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn () => PlannerEntry::forUser($user->id)->get()
        )
            ->filter(fn (PlannerEntry $entry) => $entry->date->toDateString() >= $from
                && $entry->date->toDateString() <= $to)
            ->sortBy([
                ['date', 'asc'],
                ['start_minutes', 'asc'],
                ['sort_order', 'asc'],
            ])
            ->values();
    }

    public function create(User $user, array $data): PlannerEntry
    {
        $data['user_id'] = $user->id;
        $entry = PlannerEntry::create($data);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $entry->fresh();
    }

    public function update(PlannerEntry $entry, array $data): PlannerEntry
    {
        $userId = $entry->user_id;
        $entry->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $entry->fresh();
    }

    public function destroy(PlannerEntry $entry): void
    {
        $userId = $entry->user_id;
        $entry->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
