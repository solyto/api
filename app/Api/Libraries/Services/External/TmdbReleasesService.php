<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\TmdbMovieDTO;
use Carbon\Carbon;

class TmdbReleasesService
{
    private const array MOVIE_GENRE_MAP = [
        'action'           => 28,
        'adventure'        => 12,
        'animation'        => 16,
        'comedy'           => 35,
        'crime'            => 80,
        'documentary'      => 99,
        'drama'            => 18,
        'family'           => 10751,
        'fantasy'          => 14,
        'history'          => 36,
        'horror'           => 27,
        'mystery'          => 9648,
        'romance'          => 10749,
        'science fiction'  => 878,
        'sci-fi'           => 878,
        'thriller'         => 53,
        'war'              => 10752,
        'western'          => 37,
    ];

    private const array TV_GENRE_MAP = [
        'action'           => 10759,
        'adventure'        => 10759,
        'animation'        => 16,
        'comedy'           => 35,
        'crime'            => 80,
        'documentary'      => 99,
        'drama'            => 18,
        'family'           => 10751,
        'fantasy'          => 10765,
        'mystery'          => 9648,
        'reality'          => 10764,
        'science fiction'  => 10765,
        'sci-fi'           => 10765,
        'western'          => 37,
    ];

    public function __construct(private readonly TmdbApiService $tmdbApiService) {}

    /** @return TmdbMovieDTO[] */
    public function getReleasesForGenres(array $genreNames): array
    {
        $movieGenreIds = $this->mapGenres($genreNames, self::MOVIE_GENRE_MAP);
        $tvGenreIds    = $this->mapGenres($genreNames, self::TV_GENRE_MAP);

        $releases = [];
        $seen     = [];

        foreach ($this->tmdbApiService->getUpcomingMovies($movieGenreIds) ?? [] as $item) {
            $release = $this->buildMovieDTO($item, $seen);
            if ($release) {
                $releases[] = $release;
                $seen[]     = $item['id'];
            }
        }

        foreach ($this->tmdbApiService->getUpcomingTvShows($tvGenreIds) ?? [] as $item) {
            $release = $this->buildTvDTO($item, $seen);
            if ($release) {
                $releases[] = $release;
                $seen[]     = $item['id'];
            }
        }

        usort($releases, fn($a, $b) => $a->getReleaseDate()->timestamp <=> $b->getReleaseDate()->timestamp);

        return $releases;
    }

    private function buildMovieDTO(array $item, array $seen): ?TmdbMovieDTO
    {
        if (in_array($item['id'], $seen) || empty($item['release_date'])) {
            return null;
        }

        try {
            return new TmdbMovieDTO(
                id: $item['id'],
                title: $item['title'],
                overview: $item['overview'] ?? null,
                poster: isset($item['poster_path']) ? TmdbApiService::IMAGE_BASE_URL . $item['poster_path'] : null,
                releaseDate: Carbon::createFromFormat('Y-m-d', $item['release_date']),
                type: 'movie',
                url: 'https://www.themoviedb.org/movie/' . $item['id'],
            );
        } catch (\Exception) {
            return null;
        }
    }

    private function buildTvDTO(array $item, array $seen): ?TmdbMovieDTO
    {
        if (in_array($item['id'], $seen) || empty($item['first_air_date'])) {
            return null;
        }

        try {
            return new TmdbMovieDTO(
                id: $item['id'],
                title: $item['name'],
                overview: $item['overview'] ?? null,
                poster: isset($item['poster_path']) ? TmdbApiService::IMAGE_BASE_URL . $item['poster_path'] : null,
                releaseDate: Carbon::createFromFormat('Y-m-d', $item['first_air_date']),
                type: 'tv',
                url: 'https://www.themoviedb.org/tv/' . $item['id'],
            );
        } catch (\Exception) {
            return null;
        }
    }

    private function mapGenres(array $genreNames, array $map): array
    {
        $ids = [];

        foreach ($genreNames as $name) {
            $lower = strtolower($name);
            foreach ($map as $key => $id) {
                if (str_contains($lower, $key) || str_contains($key, $lower)) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
