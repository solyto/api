<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\DiscogsReleaseDTO;
use App\Api\Libraries\Services\External\DiscogsApiService;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class DiscogsImportService
{
    public function __construct(
        private readonly DiscogsApiService $discogsApiService,
    ) {}

    public function importAlbumFromUrl(string $url): ?DiscogsReleaseDTO
    {
        $releaseId = $this->getReleaseIdFromUrl($url);
        $result = $this->discogsApiService->getRelease($releaseId);

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

    private function getReleaseIdFromUrl(string $url): int
    {
        preg_match('/release\/(\d+)/', $url, $matches);
        return (int) $matches[1];
    }
}
