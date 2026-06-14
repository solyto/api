<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\SteamGameDTO;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SteamService
{
    private const string GET_APP_DETAILS_URL = 'https://store.steampowered.com/api/appdetails?appids=%d';
    private const string SEARCH_URL = 'https://store.steampowered.com/api/storesearch?term=%s&l=english&cc=US';

    public function importFromUrl(string $url): ?SteamGameDTO
    {
        $appId = $this->getAppIdFromUrl($url);

        if (!$appId) {
            return null;
        }

        $result = $this->getAppDetails($appId);

        if (!$result) {
            return null;
        }

        $releaseDate = null;
        if (isset($result['release_date']['date']) && !$result['release_date']['coming_soon']) {
            $releaseDate = $result['release_date']['date'];
        }

        $genres = [];
        if (isset($result['genres'])) {
            $genres = array_map(fn($genre) => $genre['description'], $result['genres']);
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

    public function searchGames(string $query): ?array
    {
        try {
            $response = Http::get(sprintf(self::SEARCH_URL, urlencode($query)));

            if (!$response->successful()) {
                return null;
            }

            return $response->json()['items'] ?? null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    private function getAppDetails(int $appId): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_APP_DETAILS_URL, $appId));

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if (!isset($data[$appId]) || !$data[$appId]['success']) {
                return null;
            }

            return $data[$appId]['data'];
        } catch (ConnectionException $e) {
            return null;
        }
    }

    private function getAppIdFromUrl(string $url): ?int
    {
        if (preg_match('/store\.steampowered\.com\/app\/(\d+)/', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
