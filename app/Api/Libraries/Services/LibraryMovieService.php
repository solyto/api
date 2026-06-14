<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\Enums\LibraryTypeEnum;
use App\Api\Libraries\Enums\MovieServiceEnum;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMovieGenre;
use App\Api\Libraries\Services\External\ImdbService;
use App\Api\Libraries\Services\External\TmdbService;
use App\Api\Users\Models\User;
use App\Shared\Services\UserCacheService;
use Illuminate\Support\Collection;

class LibraryMovieService
{
    private const string CACHE_KEY = 'movies';

    private const string CACHE_KEY_RELEASES = 'movie_releases';

    private const int CACHE_TTL = 86400;

    private const int CACHE_TTL_RELEASES = 604800;

    public function __construct(
        private readonly LibraryCoverService $coverService,
        private readonly ImdbService $imdbService,
        private readonly TmdbService $tmdbService,
        private readonly UserCacheService $cache,
    ) {}

    public function list(User $user): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY, $user->id],
            self::CACHE_TTL,
            fn () => LibraryMovie::forUser($user->id)->orderBy('title', 'asc')->with(['genres', 'tags'])->get()
        );
    }

    public function find(LibraryMovie $movie): LibraryMovie
    {
        $movie->load(['genres', 'tags']);

        return $movie;
    }

    public function create(User $user, array $data): LibraryMovie
    {
        $data['user_id'] = $user->id;

        if (! empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($data['user_id'], $data['cover_path'], LibraryTypeEnum::MOVIE);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $movie = LibraryMovie::create($data);

        if (isset($data['genres'])) {
            $movie->genres()->attach($data['genres']);
        }

        if (isset($data['tags'])) {
            $movie->tags()->attach($data['tags']);
        }

        $movie->load(['user', 'genres', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $movie;
    }

    public function update(LibraryMovie $movie, array $data): LibraryMovie
    {
        if (! empty($data['cover_path'])) {
            $save = $this->coverService->saveCover($movie->user_id, $data['cover_path'], LibraryTypeEnum::MOVIE);
            if ($save) {
                $data['cover_path'] = $save;
            }
        }

        $movie->update($data);

        if (array_key_exists('genres', $data)) {
            $movie->genres()->sync($data['genres']);
        }

        if (array_key_exists('tags', $data)) {
            $movie->tags()->sync($data['tags']);
        }

        $movie->load(['user', 'genres', 'tags']);

        $this->cache->forget([self::CACHE_KEY, $movie->user_id]);

        return $movie;
    }

    public function destroy(LibraryMovie $movie): void
    {
        $userId = $movie->user_id;
        $movie->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }

    public function releases(User $user): array
    {
        return $this->cache->remember(
            [self::CACHE_KEY_RELEASES, $user->id],
            self::CACHE_TTL_RELEASES,
            function () use ($user) {
                $service = app()->makeWith(LibraryReleases::class, ['user' => $user]);

                return $service->getMovieReleases();
            }
        );
    }

    public function trailers(LibraryMovie $movie): array
    {
        if ($movie->category === 'series') {
            $results = $this->tmdbService->searchTv($movie->title);
            if (empty($results)) {
                return [];
            }
            $videos = $this->tmdbService->getTvVideos($results[0]['id']);
        } else {
            $results = $this->tmdbService->searchMovie($movie->title, $movie->publication_year);
            if (empty($results)) {
                return [];
            }
            $videos = $this->tmdbService->getMovieVideos($results[0]['id']);
        }

        return collect($videos ?? [])
            ->filter(fn ($v) => $v['site'] === 'YouTube' && $v['type'] === 'Trailer')
            ->values()
            ->toArray();
    }

    public function search(MovieServiceEnum $service, string $query): ?array
    {
        return match ($service) {
            MovieServiceEnum::TMDB => $this->tmdbService->searchMovie($query),
            MovieServiceEnum::IMDB => [],
        };
    }

    public function import(MovieServiceEnum $service, string $url): mixed
    {
        return match ($service) {
            MovieServiceEnum::IMDB => $this->imdbService->importFromUrl($url),
            MovieServiceEnum::TMDB => $this->tmdbService->importFromUrl($url),
        };
    }

    public function listGenres(User $user): Collection
    {
        return LibraryMovieGenre::forUser($user->id)->get();
    }

    public function findGenre(LibraryMovieGenre $genre): LibraryMovieGenre
    {
        return $genre;
    }

    public function createGenre(User $user, array $data): LibraryMovieGenre
    {
        $data['user_id'] = $user->id;
        $genre = LibraryMovieGenre::create($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $user->id]);

        return $genre;
    }

    public function updateGenre(LibraryMovieGenre $genre, array $data): LibraryMovieGenre
    {
        $userId = $genre->user_id;
        $genre->update($data);
        $genre->load(['user']);

        $this->cache->forget([self::CACHE_KEY, $userId]);

        return $genre;
    }

    public function destroyGenre(LibraryMovieGenre $genre): void
    {
        $userId = $genre->user_id;
        $genre->delete();

        $this->cache->forget([self::CACHE_KEY, $userId]);
    }
}
