<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\MovieReleaseDTO;
use App\Api\Libraries\Enums\MovieServiceEnum;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ImdbService
{
    private const string GET_MOVIE_URL = 'https://api.imdbapi.dev/titles/%s';

    public function importFromUrl(string $url): ?MovieReleaseDTO
    {
        $titleId = $this->getTitleIdFromUrl($url);

        if (!$titleId) {
            return null;
        }

        $result = $this->getMovie($titleId);

        if (!$result) {
            return null;
        }

        return new MovieReleaseDTO(
            id: $result['id'],
            title: $result['primaryTitle'],
            url: $url,
            provider: MovieServiceEnum::IMDB->value,
            type: $result['type'],
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
