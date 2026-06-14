<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\ImdbMovieDTO;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ImdbService
{
    private const string GET_MOVIE_URL = 'https://api.imdbapi.dev/titles/%s';

    public function importFromUrl(string $url): ?ImdbMovieDTO
    {
        $titleId = $this->getTitleIdFromUrl($url);

        if (!$titleId) {
            return null;
        }

        $result = $this->getMovie($titleId);

        if (!$result) {
            return null;
        }

        return new ImdbMovieDTO(
            id: $result['id'],
            type: $result['type'],
            title: $result['primaryTitle'],
            description: $result['plot'],
            cover: $result['primaryImage']['url'] ?? null,
            releaseYear: $result['startYear'],
            runtime: $result['runtimeSeconds'] ? $result['runtimeSeconds'] / 60 : null,
            genres: $result['genres'],
        );
    }

    private function getMovie(string $titleId): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_MOVIE_URL, $titleId));

            if (!$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    private function getTitleIdFromUrl(string $url): ?string
    {
        if (preg_match('#/title/(tt\d+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
