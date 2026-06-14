<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\GameReleaseDTO;
use App\Api\Libraries\DTOs\GameSearchResultDTO;
use App\Api\Libraries\Enums\GameServiceEnum;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SteamService
{
    private const string STORE_URL = 'https://store.steampowered.com/app/%d';
    private const string GET_APP_DETAILS_URL = 'https://store.steampowered.com/api/appdetails?appids=%d';
    private const string SEARCH_URL = 'https://store.steampowered.com/api/storesearch?term=%s&l=english&cc=US';

    public static function getStoreUrl(int $id): string
    {
        return sprintf(self::STORE_URL, $id);
    }

    public function importFromUrl(string $url): ?GameReleaseDTO
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

        $publicationYear = null;
        if ($releaseDate) {
            try {
                $publicationYear = Carbon::parse($releaseDate)->year;
            } catch (InvalidFormatException) {}
        }

        $genres = [];
        if (isset($result['genres'])) {
            $genres = array_map(fn($genre) => $genre['description'], $result['genres']);
        }

        return new GameReleaseDTO(
            id: $appId,
            title: $result['name'],
            url: $url,
            provider: GameServiceEnum::STEAM->value,
            cover: $result['header_image'] ?? null,
            description: $result['short_description'] ?? null,
            publicationYear: $publicationYear,
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

            $items = $response->json()['items'] ?? null;

            if (!is_array($items)) {
                return null;
            }

            return array_map(fn($item) => new GameSearchResultDTO(
                id: (int) $item['id'],
                title: $item['name'],
                cover: $item['tiny_image'] ?? null,
                releaseYear: null,
                provider: GameServiceEnum::STEAM->value,
                url: self::getStoreUrl((int) $item['id']),
            ), $items);
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
