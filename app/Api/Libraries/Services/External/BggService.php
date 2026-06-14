<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\GameReleaseDTO;
use App\Api\Libraries\DTOs\GameSearchResultDTO;
use App\Api\Libraries\Enums\GameServiceEnum;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BggService
{
    private const string GAME_URL = 'https://boardgamegeek.com/boardgame/%d';
    private const string GET_THING_URL = 'https://boardgamegeek.com/xmlapi2/thing?id=%d';
    private const string SEARCH_URL = 'https://boardgamegeek.com/xmlapi2/search?query=%s&type=boardgame';

    public static function getGameUrl(int $id): string
    {
        return sprintf(self::GAME_URL, $id);
    }

    public function importFromUrl(string $url): ?GameReleaseDTO
    {
        $gameId = $this->getGameIdFromUrl($url);

        if (!$gameId) {
            return null;
        }

        $result = $this->getGameDetails($gameId);

        if (!$result) {
            return null;
        }

        return new GameReleaseDTO(
            id: $gameId,
            title: $result['title'],
            url: $url,
            provider: GameServiceEnum::BGG->value,
            cover: $result['cover'],
            description: $result['description'],
            publicationYear: $result['year_published'],
            developer: $result['designer'],
            publisher: $result['publisher'],
            genres: $result['genres'],
        );
    }

    public function searchGames(string $query): ?array
    {
        try {
            $response = Http::withHeaders(['Accept' => 'application/xml'])
                ->get(sprintf(self::SEARCH_URL, urlencode($query)));

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

                $id = (int) $item['id'];
                $results[] = new GameSearchResultDTO(
                    id: $id,
                    title: $title,
                    cover: null,
                    releaseYear: isset($item->yearpublished) ? (int) $item->yearpublished['value'] : null,
                    provider: GameServiceEnum::BGG->value,
                    url: self::getGameUrl($id),
                );
            }

            return $results;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    private function getGameDetails(int $gameId): ?array
    {
        try {
            $url = sprintf(self::GET_THING_URL, $gameId);

            $headers = ['Accept' => 'application/xml'];

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

    private function getGameIdFromUrl(string $url): ?int
    {
        if (preg_match('/boardgamegeek\.com\/boardgame\/(\d+)/', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
