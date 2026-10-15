<?php

namespace App\Api\Finances\Services;

use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\BudgetValue;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $budget = DB::transaction(function () use ($data) {
            $budget = Budget::create($data);
            $budget->values()->create([
                'date' => Carbon::today()->toDateString(),
                'value' => $data['value'],
            ]);

            return $budget;
        });
        $budget->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $budget;
    }

    public function update(Budget $budget, array $data): Budget
    {
        $userId = $budget->user_id;

        DB::transaction(function () use ($budget, $data) {
            $budget->update(collect($data)->except('value')->all());

            if (array_key_exists('value', $data)) {
                $this->writeValue($budget, Carbon::today()->toDateString(), $data['value']);
                $this->syncCurrentValue($budget);
            }
        });

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $budget->fresh(['user']);
    }

    public function destroy(Budget $budget): void
    {
        $userId = $budget->user_id;
        $budget->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function listValues(Budget $budget): Collection
    {
        return $budget->values()->orderBy('date')->get();
    }

    public function storeValue(Budget $budget, array $data): BudgetValue
    {
        $value = DB::transaction(function () use ($budget, $data) {
            $value = $this->writeValue($budget, $data['date'] ?? Carbon::today()->toDateString(), $data['value']);
            $this->syncCurrentValue($budget);

            return $value;
        });

        $this->cache->forget([self::CACHE_KEY, $budget->user_id]);

        return $value;
    }

    public function updateValue(Budget $budget, BudgetValue $value, array $data): BudgetValue
    {
        if (isset($data['date'])) {
            $taken = $budget->values()
                ->whereDate('date', $data['date'])
                ->whereKeyNot($value->id)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'date' => 'The budget item already has a value on this date.',
                ]);
            }
        }

        DB::transaction(function () use ($budget, $value, $data) {
            $value->update($data);
            $this->syncCurrentValue($budget);
        });

        $this->cache->forget([self::CACHE_KEY, $budget->user_id]);

        return $value;
    }

    public function destroyValue(Budget $budget, BudgetValue $value): void
    {
        // budgets.value is non-null and read by older clients, so an item keeps at least one value.
        abort_if($budget->values()->count() <= 1, 422, 'A budget item needs at least one value.');

        DB::transaction(function () use ($budget, $value) {
            $value->delete();
            $this->syncCurrentValue($budget);
        });

        $this->cache->forget([self::CACHE_KEY, $budget->user_id]);
    }

    private function writeValue(Budget $budget, string $date, float|int|string $amount): BudgetValue
    {
        $value = BudgetValue::where('budget_id', $budget->id)->whereDate('date', $date)->first();

        if ($value) {
            $value->update(['value' => $amount]);

            return $value;
        }

        return BudgetValue::create([
            'date' => $date,
            'value' => $amount,
            'budget_id' => $budget->id,
        ]);
    }

    private function syncCurrentValue(Budget $budget): void
    {
        $latest = BudgetValue::where('budget_id', $budget->id)->orderByDesc('date')->firstOrFail();

        $budget->update(['value' => $latest->value]);
    }
}
