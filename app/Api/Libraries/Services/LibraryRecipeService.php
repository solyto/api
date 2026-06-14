<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Enums\RecipeServiceEnum;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Libraries\Services\External\ChefkochService;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryRecipeService
{
    private const string CACHE_KEY = 'recipes';

    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly ChefkochService $chefkochService,
        private readonly UserCacheService $cache,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn () => LibraryRecipe::forUser($user->id)->orderBy('title', 'asc')->get()
        );
    }

    public function find(LibraryRecipe $recipe): LibraryRecipe
    {
        return $recipe;
    }

    public function create(User $user, array $data): LibraryRecipe
    {
        $data['user_id'] = $user->id;

        if (! empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($data['user_id'], $data['cover_path'], LibraryTypeEnum::RECIPE);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $recipe = LibraryRecipe::create($data);
        $recipe->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $recipe;
    }

    public function update(LibraryRecipe $recipe, array $data): LibraryRecipe
    {
        if (! empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($recipe->user_id, $data['cover_path'], LibraryTypeEnum::RECIPE);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $recipe->update($data);
        $recipe->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $recipe->user_id]);

        return $recipe;
    }

    public function destroy(LibraryRecipe $recipe): void
    {
        $userId = $recipe->user_id;
        $recipe->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function import(RecipeServiceEnum $service, string $url): mixed
    {
        return match ($service) {
            RecipeServiceEnum::CHEFKOCH => $this->chefkochService->importFromUrl($url),
        };
    }
}
