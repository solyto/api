<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\DiscogsReleaseDTO;
use Calliostro\Discogs\ClientFactory;
use Calliostro\Discogs\DiscogsApiClient;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class DiscogsService
{
    private readonly DiscogsApiClient $client;

    public function __construct()
    {
        $this->client = ClientFactory::create();
    }

    public function importFromUrl(string $url): ?DiscogsReleaseDTO
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

        return new DiscogsReleaseDTO(
            id: $result['id'],
            artist: preg_replace('/\s*\([^)]*\)/', '', $result['artists'][0]['name']) ?? null,
            artistId: $result['artists'][0]['id'] ?? null,
            title: $result['title'],
            url: $result['uri'],
            cover: $result['images'][0]['uri'] ?? null,
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

        return $result['results'] ?? null;
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
