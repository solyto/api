<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\ImdbMovieDTO;
use App\Api\Libraries\Services\External\ImdbApiService;

class ImdbImportService
{
    public function __construct(
        private readonly ImdbApiService $imdbApiService,
    ) {}

    public function importMovieFromUrl(string $url): ?ImdbMovieDTO
    {
        $titleId = $this->getTitleIdFromUrl($url);

        if (!$titleId) {
            return null;
        }

        $result = $this->imdbApiService->getMovie($titleId);

        if (!$result) {
            return null;
        }

        return new ImdbMovieDTO(
            id         : $result['id'],
            type       : $result['type'],
            title      : $result['primaryTitle'],
            description: $result['plot'],
            cover      : $result['primaryImage']['url'] ?? null,
            releaseYear: $result['startYear'],
            runtime    : $result['runtimeSeconds'] ? $result['runtimeSeconds'] / 60 : null,
            genres     : $result['genres'],
        );
    }

    private function getTitleIdFromUrl(string $url): ?string
    {
        if (preg_match('#/title/(tt\d+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
