<?php

namespace App\Api\Libraries\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TmdbApiService
{
    private const string BASE_URL = 'https://api.themoviedb.org/3';
    public const string IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w500';

    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = Http::withToken(config('services.tmdb.access_token'))
                ->get(self::BASE_URL . $path, $query);

            if (!$response->successful()) {
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

    public function searchMovie(string $title, ?int $year = null): ?array
    {
        $query = ['query' => $title, 'language' => 'en-US'];
        if ($year) {
            $query['year'] = $year;
        }

        $result = $this->get('/search/movie', $query);

        return $result['results'] ?? null;
    }

    public function searchTv(string $title): ?array
    {
        $result = $this->get('/search/tv', ['query' => $title, 'language' => 'en-US']);

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
}
