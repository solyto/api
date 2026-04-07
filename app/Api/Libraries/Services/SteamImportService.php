<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\SteamGameDTO;
use App\Api\Libraries\Services\External\SteamApiService;

class SteamImportService
{
    public function __construct(
        private readonly SteamApiService $steamApiService
    ) {}

    public function importGameFromUrl(string $url): ?SteamGameDTO
    {
        $appId = $this->getAppIdFromUrl($url);

        if (!$appId) {
            return null;
        }

        $result = $this->steamApiService->getAppDetails($appId);

        if (!$result) {
            return null;
        }

        $releaseDate = null;
        if (isset($result['release_date']['date']) && !$result['release_date']['coming_soon']) {
            $releaseDate = $result['release_date']['date'];
        }

        $genres = [];
        if (isset($result['genres'])) {
            $genres = array_map(fn ($genre) => $genre['description'], $result['genres']);
        }

        return new SteamGameDTO(
            id: $appId,
            title: $result['name'],
            url: $url,
            cover: $result['header_image'] ?? null,
            description: $result['short_description'] ?? null,
            releaseDate: $releaseDate,
            developer: $result['developers'][0] ?? null,
            publisher: $result['publishers'][0] ?? null,
            genres: $genres,
        );
    }

    private function getAppIdFromUrl(string $url): ?int
    {
        if (preg_match('/store\.steampowered\.com\/app\/(\d+)/', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
