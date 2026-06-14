<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryRecommendationEnum;
use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryMusicGenre;
use App\Api\Libraries\Services\External\DeezerService;
use App\Api\Libraries\Services\External\DiscogsService;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryMusicService
{
    private const string CACHE_KEY = 'music';
    private const string CACHE_KEY_RELEASES        = 'music_releases';
    private const string CACHE_KEY_RECOMMENDATIONS = 'music_recommendations';
    private const int CACHE_TTL                    = 86400;
    private const int CACHE_TTL_RELEASES = 86400;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly DeezerService $deezerService,
        private readonly DiscogsService $discogsService,
        private readonly UserCacheService $cache,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn() => LibraryMusic::forUser($user->id)->orderBy('artist', 'asc')->with(['genres'])->get()
        );
    }

    public function find(LibraryMusic $music): LibraryMusic
    {
        $music->load(['genres']);

        return $music;
    }

    public function create(User $user, array $data): LibraryMusic
    {
        $data['user_id'] = $user->id;

        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($data['user_id'], $data['cover_path'], LibraryTypeEnum::MUSIC);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $music = LibraryMusic::create($data);

        if (isset($data['genres'])) {
            $music->genres()->attach($data['genres']);
        }

        $music->load(['user', 'genres']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $music;
    }

    public function update(LibraryMusic $music, array $data): LibraryMusic
    {
        if (!empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($music->user_id, $data['cover_path'], LibraryTypeEnum::MUSIC);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $music->update($data);

        if (array_key_exists('genres', $data)) {
            $music->genres()->sync($data['genres']);
        }

        $music->load(['user', 'genres']);

        $this->cache->forget([self::CACHE_KEY, $music->user_id]);

        return $music;
    }

    public function destroy(LibraryMusic $music): void
    {
        $userId = $music->user_id;
        $music->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function recommend(User $user, LibraryRecommendationEnum $type): ?array
    {
        $recommender = new LibraryRecommender(LibraryTypeEnum::MUSIC, $user);

        if ($type === LibraryRecommendationEnum::NEW) {
            return $recommender->new();
        }

        return call_user_func([$recommender, $type->value]);
    }

    public function recommendNew(User $user): mixed
    {
        $stack = $this->cache->get([self::CACHE_KEY_RECOMMENDATIONS, $user->id]) ?? [];

        if (empty($stack)) {
            $result = $this->recommend($user, LibraryRecommendationEnum::NEW);
            $stack = $result['recommendations'] ?? [];
        }

        $item = array_shift($stack);

        $this->cache->store([self::CACHE_KEY_RECOMMENDATIONS, $user->id], self::CACHE_TTL, $stack);

        return $item;
    }

    public function releases(User $user): array
    {
        return $this->cache->remember(
            [self::CACHE_KEY_RELEASES, $user->id],
            self::CACHE_TTL_RELEASES,
            function () use ($user) {
                $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);
                return $service->getMusicReleases();
            }
        );
    }

    public function importFromDeezer(string $url): mixed
    {
        return $this->deezerService->importFromUrl($url);
    }

    public function importFromDiscogs(string $url): mixed
    {
        return $this->discogsService->importFromUrl($url);
    }

    public function searchOnDeezer(string $artist, string $album): mixed
    {
        return $this->deezerService->searchAlbum($artist, $album);
    }

    public function searchOnDiscogs(string $query): mixed
    {
        return $this->discogsService->search($query);
    }

    public function listGenres(User $user): Collection
    {
        return LibraryMusicGenre::forUser($user->id)->get();
    }

    public function findGenre(LibraryMusicGenre $genre): LibraryMusicGenre
    {
        return $genre;
    }

    public function createGenre(User $user, array $data): LibraryMusicGenre
    {
        $data['user_id'] = $user->id;
        $genre = LibraryMusicGenre::create($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $genre;
    }

    public function updateGenre(LibraryMusicGenre $genre, array $data): LibraryMusicGenre
    {
        $userId = $genre->user_id;
        $genre->update($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $genre;
    }

    public function destroyGenre(LibraryMusicGenre $genre): void
    {
        $userId = $genre->user_id;
        $genre->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
