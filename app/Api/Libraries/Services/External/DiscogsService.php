<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\MusicReleaseDTO;
use App\Api\Libraries\DTOs\MusicSearchResultDTO;
use App\Api\Libraries\Enums\MusicServiceEnum;
use Calliostro\Discogs\ClientFactory;
use Calliostro\Discogs\DiscogsApiClient;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class DiscogsService
{
    private const string RELEASE_URL = 'https://www.discogs.com/release/%d';

    private readonly DiscogsApiClient $client;

    public static function getReleaseUrl(int $id): string
    {
        return sprintf(self::RELEASE_URL, $id);
    }

    public function __construct()
    {
        $token = config('services.discogs.access_token');

        $this->client = $token
            ? ClientFactory::createWithToken($token)
            : ClientFactory::create();
    }

    public function importFromUrl(string $url): ?MusicReleaseDTO
    {
        $releaseId = $this->getReleaseIdFromUrl($url);
        $result = $this->getRelease($releaseId);

        if (!$result) {
            return null;
        }

        try {
            $releaseDate = Carbon::createFromFormat('Y-m-d', $result['released']);
        } catch (InvalidFormatException $e) {
            $releaseDate = null;
        }

        return new MusicReleaseDTO(
            id: $result['id'],
            artist: preg_replace('/\s*\([^)]*\)/', '', $result['artists'][0]['name']) ?? null,
            artistId: $result['artists'][0]['id'] ?? null,
            title: $result['title'],
            url: $result['uri'],
            cover: $result['images'][0]['uri'] ?? null,
            provider: MusicServiceEnum::DISCOGS->value,
            releaseDate: $releaseDate,
            genres: $result['genres'],
            recordType: $result['record_type'] ?? null,
        );
    }

    public function search(string $query): ?array
    {
        $result = $this->client->search([
            'q' => $query,
            'type' => 'release',
        ]);

        $items = $result['results'] ?? null;

        if (!is_array($items)) {
            return null;
        }

        return array_map(fn($item) => new MusicSearchResultDTO(
            id: (int) $item['id'],
            title: $item['title'],
            artist: null,
            cover: !empty($item['cover_image']) ? $item['cover_image'] : (!empty($item['thumb']) ? $item['thumb'] : null),
            releaseYear: isset($item['year']) && $item['year'] !== '' ? (int) $item['year'] : null,
            provider: MusicServiceEnum::DISCOGS->value,
            url: self::getReleaseUrl((int) $item['id']),
        ), $items);
    }

    private function getRelease(int $releaseId): mixed
    {
        return $this->client->releaseGet(['id' => $releaseId]);
    }

    private function getReleaseIdFromUrl(string $url): int
    {
        preg_match('/release\/(\d+)/', $url, $matches);
        return (int) $matches[1];
    }
}
