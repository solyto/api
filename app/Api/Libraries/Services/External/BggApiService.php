<?php

namespace App\Api\Libraries\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BggApiService
{
    private const GET_THING_URL = 'https://boardgamegeek.com/xmlapi2/thing?id=%d';
    private const SEARCH_URL = 'https://boardgamegeek.com/xmlapi2/search?query=%s&type=boardgame';

    public function searchGames(string $query): ?array
    {
        try {
            $url = sprintf(self::SEARCH_URL, urlencode($query));
            $response = Http::withHeaders(['Accept' => 'application/xml'])->get($url);

            if (!$response->successful()) {
                return null;
            }

            $xml = simplexml_load_string($response->body());

            if ($xml === false || !isset($xml->item)) {
                return [];
            }

            $results = [];
            foreach ($xml->item as $item) {
                $title = null;
                foreach ($item->name as $name) {
                    if ((string) $name['type'] === 'primary') {
                        $title = (string) $name['value'];
                        break;
                    }
                }

                if (!$title) {
                    continue;
                }

                $results[] = [
                    'id' => (int) $item['id'],
                    'title' => $title,
                    'year_published' => isset($item->yearpublished) ? (int) $item->yearpublished['value'] : null,
                ];
            }

            return $results;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function getGameDetails(int $gameId): ?array
    {
        try {
            $url = sprintf(self::GET_THING_URL, $gameId);

            $headers = [
                'Accept' => 'application/xml',
            ];

            $apiKey = config('services.bgg.api_key');
            if ($apiKey) {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            $response = Http::withHeaders($headers)->get($url);

            if ($response->status() === 202) {
                return null;
            }

            if (!$response->successful()) {
                return null;
            }

            $xml = simplexml_load_string($response->body());

            if ($xml === false || !isset($xml->item)) {
                return null;
            }

            $item = $xml->item;

            $title = null;
            foreach ($item->name as $name) {
                if ((string) $name['type'] === 'primary') {
                    $title = (string) $name['value'];
                    break;
                }
            }

            if (!$title) {
                return null;
            }

            $cover = isset($item->image) ? (string) $item->image : null;
            $description = isset($item->description) ? strip_tags(html_entity_decode((string) $item->description)) : null;
            $yearPublished = isset($item->yearpublished) ? (int) $item->yearpublished['value'] : null;

            $designers = [];
            $publishers = [];
            $categories = [];

            foreach ($item->link as $link) {
                $type = (string) $link['type'];
                $value = (string) $link['value'];

                match ($type) {
                    'boardgamedesigner' => $designers[] = $value,
                    'boardgamepublisher' => $publishers[] = $value,
                    'boardgamecategory' => $categories[] = $value,
                    default => null,
                };
            }

            return [
                'title' => $title,
                'cover' => $cover,
                'description' => $description,
                'year_published' => $yearPublished,
                'designer' => $designers[0] ?? null,
                'publisher' => $publishers[0] ?? null,
                'genres' => $categories,
            ];
        } catch (ConnectionException $e) {
            return null;
        }
    }
}
