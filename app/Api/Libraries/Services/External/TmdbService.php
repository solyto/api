<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\MovieReleaseDTO;
use App\Api\Libraries\DTOs\MovieSearchResultDTO;
use App\Api\Libraries\Enums\MovieServiceEnum;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TmdbService
{
    private const string BASE_URL = 'https://api.themoviedb.org/3';

    public const string IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w500';

    private const array MOVIE_GENRE_MAP = [
        'action' => 28,
        'adventure' => 12,
        'animation' => 16,
        'comedy' => 35,
        'crime' => 80,
        'documentary' => 99,
        'drama' => 18,
        'family' => 10751,
        'fantasy' => 14,
        'history' => 36,
        'horror' => 27,
        'mystery' => 9648,
        'romance' => 10749,
        'science fiction' => 878,
        'sci-fi' => 878,
        'thriller' => 53,
        'war' => 10752,
        'western' => 37,
    ];

    private const array TV_GENRE_MAP = [
        'action' => 10759,
        'adventure' => 10759,
        'animation' => 16,
        'comedy' => 35,
        'crime' => 80,
        'documentary' => 99,
        'drama' => 18,
        'family' => 10751,
        'fantasy' => 10765,
        'mystery' => 9648,
        'reality' => 10764,
        'science fiction' => 10765,
        'sci-fi' => 10765,
        'western' => 37,
    ];

    private const string MOVIE_URL = 'https://www.themoviedb.org/movie/%s';

    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = Http::withToken(config('services.tmdb.access_token'))
                ->get(self::BASE_URL.$path, $query);

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException) {
            return null;
        }
    }

    public function getUpcomingMovies(array $genreIds): ?array
    {
        $result = $this->get('/discover/movie', [
            'sort_by' => 'popularity.desc',
            'primary_release_date.gte' => now()->format('Y-m-d'),
            'primary_release_date.lte' => now()->addMonths(3)->format('Y-m-d'),
            'with_genres' => implode(',', $genreIds),
            'language' => 'en-US',
            'page' => 1,
        ]);

        return $result['results'] ?? null;
    }

    public function getUpcomingTvShows(array $genreIds): ?array
    {
        $result = $this->get('/discover/tv', [
            'sort_by' => 'popularity.desc',
            'first_air_date.gte' => now()->format('Y-m-d'),
            'first_air_date.lte' => now()->addMonths(3)->format('Y-m-d'),
            'with_genres' => implode(',', $genreIds),
            'language' => 'en-US',
            'page' => 1,
        ]);

        return $result['results'] ?? null;
    }

    public function searchMovie(string $query, ?int $year = null): ?array
    {
        $params = ['query' => $query, 'language' => 'en-US'];
        if ($year) {
            $params['year'] = $year;
        }

        $result = $this->get('/search/movie', $params);
        $items = $result['results'] ?? null;

        if (!is_array($items)) {
            return null;
        }

        return array_filter(array_map(function ($item) {
            if (empty($item['id']) || empty($item['title'])) {
                return null;
            }

            return new MovieSearchResultDTO(
                id: (int) $item['id'],
                title: $item['title'],
                cover: isset($item['poster_path']) ? self::IMAGE_BASE_URL . $item['poster_path'] : null,
                releaseYear: !empty($item['release_date']) ? (int) substr($item['release_date'], 0, 4) : null,
                provider: MovieServiceEnum::TMDB->value,
                url: self::getMovieUrl($item['id']),
            );
        }, $items));
    }

    public function searchTv(string $query): ?array
    {
        $result = $this->get('/search/tv', ['query' => $query, 'language' => 'en-US']);

        return $result['results'] ?? null;
    }

    public function getMovieVideos(int $tmdbId): ?array
    {
        $result = $this->get("/movie/{$tmdbId}/videos", ['language' => 'en-US']);

        return $result['results'] ?? null;
    }

    public function getTvVideos(int $tmdbId): ?array
    {
        $result = $this->get("/tv/{$tmdbId}/videos", ['language' => 'en-US']);

        return $result['results'] ?? null;
    }

    /** @return MovieReleaseDTO[] */
    public function getReleasesForGenres(array $genreNames): array
    {
        $movieGenreIds = $this->mapGenres($genreNames, self::MOVIE_GENRE_MAP);
        $tvGenreIds = $this->mapGenres($genreNames, self::TV_GENRE_MAP);

        $releases = [];
        $seen = [];

        foreach ($this->getUpcomingMovies($movieGenreIds) ?? [] as $item) {
            $release = $this->buildMovieDTO($item, $seen);
            if ($release) {
                $releases[] = $release;
                $seen[] = $item['id'];
            }
        }

        foreach ($this->getUpcomingTvShows($tvGenreIds) ?? [] as $item) {
            $release = $this->buildTvDTO($item, $seen);
            if ($release) {
                $releases[] = $release;
                $seen[] = $item['id'];
            }
        }

        usort($releases, fn ($a, $b) => $a->getReleaseYear() <=> $b->getReleaseYear());

        return $releases;
    }

    public function getMovieUrl($id): string
    {
        return sprintf(self::MOVIE_URL, $id);
    }

    public function importFromUrl(string $url): ?MovieReleaseDTO
    {
        $parsed = $this->parseUrl($url);

        if (!$parsed) {
            return null;
        }

        ['type' => $type, 'id' => $id] = $parsed;

        $result = $this->get("/{$type}/{$id}", ['language' => 'en-US']);

        if (!$result) {
            return null;
        }

        try {
            $genres = array_map(fn($g) => $g['name'], $result['genres'] ?? []);

            if ($type === 'movie') {
                return new MovieReleaseDTO(
                    id: (string) $result['id'],
                    title: $result['title'],
                    url: $url,
                    provider: MovieServiceEnum::TMDB->value,
                    type: 'movie',
                    description: $result['overview'] ?? null,
                    cover: isset($result['poster_path']) ? self::IMAGE_BASE_URL . $result['poster_path'] : null,
                    releaseYear: !empty($result['release_date']) ? (int) substr($result['release_date'], 0, 4) : null,
                    runtime: $result['runtime'] ?? null,
                    genres: $genres,
                );
            }

            return new MovieReleaseDTO(
                id: (string) $result['id'],
                title: $result['name'],
                url: $url,
                provider: MovieServiceEnum::TMDB->value,
                type: 'tv',
                description: $result['overview'] ?? null,
                cover: isset($result['poster_path']) ? self::IMAGE_BASE_URL . $result['poster_path'] : null,
                releaseYear: !empty($result['first_air_date']) ? (int) substr($result['first_air_date'], 0, 4) : null,
                runtime: $result['episode_run_time'][0] ?? null,
                genres: $genres,
            );
        } catch (\Exception) {
            return null;
        }
    }

    private function parseUrl(string $url): ?array
    {
        if (preg_match('#themoviedb\.org/(movie|tv)/(\d+)#', $url, $matches)) {
            return ['type' => $matches[1], 'id' => (int) $matches[2]];
        }

        return null;
    }

    private function buildMovieDTO(array $item, array $seen): ?MovieReleaseDTO
    {
        if (in_array($item['id'], $seen) || empty($item['release_date'])) {
            return null;
        }

        try {
            return new MovieReleaseDTO(
                id: $item['id'],
                title: $item['title'],
                url: self::getMovieUrl($item['id']),
                provider: MovieServiceEnum::TMDB->value,
                type: 'movie',
                description: $item['overview'] ?? null,
                cover: isset($item['poster_path']) ? self::IMAGE_BASE_URL.$item['poster_path'] : null,
                releaseYear: Carbon::createFromFormat('Y-m-d', $item['release_date'])?->year,
            );
        } catch (\Exception) {
            return null;
        }
    }

    private function buildTvDTO(array $item, array $seen): ?MovieReleaseDTO
    {
        if (in_array($item['id'], $seen) || empty($item['first_air_date'])) {
            return null;
        }

        try {
            return new MovieReleaseDTO(
                id: $item['id'],
                title: $item['name'],
                url: self::getMovieUrl($item['id']),
                provider: MovieServiceEnum::TMDB->value,
                type: 'tv',
                description: $item['overview'] ?? null,
                cover: isset($item['poster_path']) ? self::IMAGE_BASE_URL.$item['poster_path'] : null,
                releaseYear: Carbon::createFromFormat('Y-m-d', $item['first_air_date'])?->year,
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
