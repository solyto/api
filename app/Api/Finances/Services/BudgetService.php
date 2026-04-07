<?php

namespace App\Api\Finances\Services;

use App\Api\Finances\Models\Budget;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class BudgetService
{
    private const string CACHE_KEY = 'budgets';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => Budget::forUser($user->id)->get()
        );
    }

    public function create(User $user, array $data): Budget
    {
        $data['user_id'] = $user->id;
        $budget = Budget::create($data);
        $budget->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $budget;
    }

    public function update(Budget $budget, array $data): Budget
    {
        $userId = $budget->user_id;
        $budget->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $budget->fresh(['user']);
    }

    public function destroy(Budget $budget): void
    {
        $userId = $budget->user_id;
        $budget->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
