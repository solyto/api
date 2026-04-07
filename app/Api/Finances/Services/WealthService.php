<?php

namespace App\Api\Finances\Services;

use App\Api\Finances\Models\WealthField;
use App\Api\Finances\Models\WealthValue;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

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
            fn() => WealthField::forUser($user->id)->with(['values'])->get()
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

    public function updateValue(WealthField $field, array $data): WealthValue
    {
        $value = WealthValue::create([
            'date' => date('Y-m-d'),
            'value' => $data['value'],
            'field_id' => $field->id,
        ]);

        $this->cache->forget([self::CACHE_KEY, $field->user_id]);

        return $value;
    }
}
