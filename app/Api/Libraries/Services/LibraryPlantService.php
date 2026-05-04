<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryPlant;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryPlantService
{
    private const string CACHE_KEY = 'plants';
    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly UserCacheService $cache,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => LibraryPlant::forUser($user->id)->orderBy('name', 'asc')->get()
        );
    }

    public function find(LibraryPlant $plant): LibraryPlant
    {
        return $plant;
    }

    public function create(User $user, array $data): LibraryPlant
    {
        $data['user_id'] = $user->id;

        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($data['user_id'], $data['cover_path'], LibraryTypeEnum::PLANT);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $plant = LibraryPlant::create($data);

        $plant->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $plant;
    }

    public function update(LibraryPlant $plant, array $data): LibraryPlant
    {
        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($plant->user_id, $data['cover_path'], LibraryTypeEnum::PLANT);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $plant->update($data);

        $plant->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $plant->user_id]);

        return $plant;
    }

    public function destroy(LibraryPlant $plant): void
    {
        $userId = $plant->user_id;
        $plant->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
