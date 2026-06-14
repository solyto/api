<?php

namespace App\Api\Libraries\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SteamApiService
{
    private const GET_APP_DETAILS_URL = 'https://store.steampowered.com/api/appdetails?appids=%d';
    private const SEARCH_URL = 'https://store.steampowered.com/api/storesearch?term=%s&l=english&cc=US';

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

    public function getAppDetails(int $appId): ?array
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
}
