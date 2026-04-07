<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryGameGenre;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryGameService
{
    private const string CACHE_KEY = 'games';
    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly SteamImportService $steamImportService,
        private readonly BggImportService $bggImportService,
        private readonly UserCacheService $cache,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => LibraryGame::forUser($user->id)->orderBy('title', 'asc')->with(['genres', 'tags'])->get()
        );
    }

    public function find(LibraryGame $game): LibraryGame
    {
        $game->load(['genres', 'tags']);

        return $game;
    }

    public function create(User $user, array $data): LibraryGame
    {
        $data['user_id'] = $user->id;

        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($data['user_id'], $data['cover_path'], LibraryTypeEnum::GAME);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $game = LibraryGame::create($data);

        if (isset($data['genres'])) {
            $game->genres()->attach($data['genres']);
        }

        if (isset($data['tags'])) {
            $game->tags()->attach($data['tags']);
        }

        $game->load(['user', 'genres', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $game;
    }

    public function update(LibraryGame $game, array $data): LibraryGame
    {
        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($game->user_id, $data['cover_path'], LibraryTypeEnum::GAME);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $game->update($data);

        if (array_key_exists('genres', $data)) {
            $game->genres()->sync($data['genres']);
        }

        if (array_key_exists('tags', $data)) {
            $game->tags()->sync($data['tags']);
        }

        $game->load(['user', 'genres', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $game->user_id]);

        return $game;
    }

    public function destroy(LibraryGame $game): void
    {
        $userId = $game->user_id;
        $game->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function importFromSteam(string $url): mixed
    {
        return $this->steamImportService->importGameFromUrl($url);
    }

    public function importFromBgg(string $url): mixed
    {
        return $this->bggImportService->importGameFromUrl($url);
    }

    public function listGenres(User $user): Collection
    {
        return LibraryGameGenre::forUser($user->id)->get();
    }

    public function findGenre(LibraryGameGenre $genre): LibraryGameGenre
    {
        return $genre;
    }

    public function createGenre(User $user, array $data): LibraryGameGenre
    {
        $data['user_id'] = $user->id;
        $genre = LibraryGameGenre::create($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $genre;
    }

    public function updateGenre(LibraryGameGenre $genre, array $data): LibraryGameGenre
    {
        $userId = $genre->user_id;
        $genre->update($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $genre;
    }

    public function destroyGenre(LibraryGameGenre $genre): void
    {
        $userId = $genre->user_id;
        $genre->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
