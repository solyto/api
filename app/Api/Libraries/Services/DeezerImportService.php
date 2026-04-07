<?php

namespace App\Api\Libraries\Services;

use App\Api\Libraries\DTOs\DeezerAlbumDTO;
use App\Api\Libraries\Services\External\DeezerApiService;
use Carbon\Carbon;

class DeezerImportService
{
    public function __construct(
        private readonly DeezerApiService $deezerApiService
    ) {}

    public function importAlbumFromUrl(string $url): ?DeezerAlbumDTO
    {
        $albumId = $this->getAlbumIdFromUrl($url);
        $result = $this->deezerApiService->getAlbum($albumId);

        if (!$result) {
            return null;
        }

        return new DeezerAlbumDTO(
            id: $result['id'],
            artist: $result['artist']['name'],
            artistId: $result['artist']['id'],
            title: $result['title'],
            url: $result['link'],
            cover: $result['cover_big'],
            releaseDate: Carbon::createFromFormat('Y-m-d', $result['release_date']),
            genres: array_map(fn ($genre) => $genre['name'], $result['genres']['data']),
            recordType: $result['record_type'] ?? null,
        );
    }

    private function getAlbumIdFromUrl(string $url): int
    {
        return (int) substr(parse_url($url, PHP_URL_PATH), strrpos(parse_url($url, PHP_URL_PATH), '/') + 1);
    }
}
