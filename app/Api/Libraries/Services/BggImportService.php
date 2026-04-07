<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\BggGameDTO;
use App\Api\Libraries\Services\External\BggApiService;

class BggImportService
{
    public function __construct(
        private readonly BggApiService $bggApiService
    ) {}

    public function importGameFromUrl(string $url): ?BggGameDTO
    {
        $gameId = $this->getGameIdFromUrl($url);

        if (!$gameId) {
            return null;
        }

        $result = $this->bggApiService->getGameDetails($gameId);

        if (!$result) {
            return null;
        }

        return new BggGameDTO(
            id: $gameId,
            title: $result['title'],
            url: $url,
            cover: $result['cover'],
            description: $result['description'],
            publicationYear: $result['year_published'],
            designer: $result['designer'],
            publisher: $result['publisher'],
            genres: $result['genres'],
        );
    }

    private function getGameIdFromUrl(string $url): ?int
    {
        if (preg_match('/boardgamegeek\.com\/boardgame\/(\d+)/', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
