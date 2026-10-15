<?php

namespace App\Api\Finances\Services;

use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WealthService
{
    private const string CACHE_KEY = 'wealth_fields';
    private const int CACHE_TTL = 86400;

    public function __construct(private readonly UserCacheService $cache) {}

    public function listFields(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => WealthField::forUser($user->id)
                ->with(['values' => fn ($query) => $query->orderBy('date')])
                ->get()
                ->sortByDesc(fn (WealthField $field) => $field->values->last()->value ?? -INF)
                ->values()
        );
    }

    public function createField(User $user, array $data): WealthField
    {
        $data['user_id'] = $user->id;
        $field = WealthField::create($data);
        $field->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $field;
    }

    public function updateField(WealthField $field, array $data): WealthField
    {
        $userId = $field->user_id;
        $field->update($data);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $field->fresh(['user']);
    }

    public function destroyField(WealthField $field): void
    {
        $userId = $field->user_id;
        $field->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function storeValue(WealthField $field, array $data): WealthValue
    {
        $date = $data['date'] ?? Carbon::today()->toDateString();

        $value = WealthValue::where('field_id', $field->id)->whereDate('date', $date)->first();

        if ($value) {
            $value->update(['value' => $data['value']]);
        } else {
            $value = WealthValue::create([
                'date' => $date,
                'value' => $data['value'],
                'field_id' => $field->id,
            ]);
        }

        $this->cache->forget([self::CACHE_KEY, $field->user_id]);

        return $value;
    }

    public function updateValue(WealthField $field, WealthValue $value, array $data): WealthValue
    {
        if (isset($data['date'])) {
            $taken = $field->values()
                ->whereDate('date', $data['date'])
                ->whereKeyNot($value->id)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'date' => 'The field already has a value on this date.',
                ]);
            }
        }

        $value->update($data);

        $this->cache->forget([self::CACHE_KEY, $field->user_id]);

        return $value;
    }

    public function destroyValue(WealthField $field, WealthValue $value): void
    {
        $value->delete();

        $this->cache->forget([self::CACHE_KEY, $field->user_id]);
    }
}
