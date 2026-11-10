<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\MovieReleaseDTO;

class ImdbService
{
    public function __construct(
        private readonly TmdbService $tmdbService
    ) {}

    public function importFromUrl(string $url): ?MovieReleaseDTO
    {
        $titleId = $this->getTitleIdFromUrl($url);

        if (!$titleId) {
            return null;
        }

        return $this->tmdbService->importByImdbId($titleId, $url);
    }

    private function getTitleIdFromUrl(string $url): ?string
    {
        if (preg_match('#/title/(tt\d+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
