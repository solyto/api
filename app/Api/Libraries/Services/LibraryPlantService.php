<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryPlant;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Http\UploadedFile;
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

    public function uploadCover(LibraryPlant $plant, UploadedFile $file): LibraryPlant | false
    {
        $filename = $this->coverService->uploadCover($plant->user_id, $file, LibraryTypeEnum::PLANT);
        if (!$filename) {
            return false;
        }

        $oldCoverPath = $plant->cover_path;
        $plant->update(['cover_path' => $filename]);

        if (!empty($oldCoverPath)) {
            $this->coverService->deleteCover($plant->user_id, LibraryTypeEnum::PLANT, $oldCoverPath);
        }

        $plant->load(['user']);
        $this->cache->forget([self::CACHE_KEY, $plant->user_id]);

        return $plant;
    }

    public function update(LibraryPlant $plant, array $data): LibraryPlant
    {
        $oldCoverPath = null;

        if (array_key_exists('cover_path', $data) && is_null($data['cover_path'])) {
            $oldCoverPath = $plant->cover_path;
        } elseif (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($plant->user_id, $data['cover_path'], LibraryTypeEnum::PLANT);
            if ($save) {
                $oldCoverPath = $plant->cover_path;
                $data['cover_path'] = $save;
            }
        }

        $plant->update($data);

        if (!empty($oldCoverPath)) {
            $this->coverService->deleteCover($plant->user_id, LibraryTypeEnum::PLANT, $oldCoverPath);
        }

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
