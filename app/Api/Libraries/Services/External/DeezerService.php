<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\DeezerAlbumDTO;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class DeezerService
{
    private const string SEARCH_ARTIST_URL = 'https://api.deezer.com/search/artist?q=%s';
    private const string SEARCH_ALBUM_URL = 'https://api.deezer.com/search/album?q=%s';
    private const string GET_ALBUMS_URL = 'https://api.deezer.com/artist/%d/albums';
    private const string GET_ALBUM_URL = 'https://api.deezer.com/album/%d';

    public function importFromUrl(string $url): ?DeezerAlbumDTO
    {
        $albumId = $this->getAlbumIdFromUrl($url);
        $result = $this->getAlbum($albumId);

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
            genres: array_map(fn($genre) => $genre['name'], $result['genres']['data']),
            recordType: $result['record_type'] ?? null,
        );
    }

    public function searchArtists(string $artist): ?array
    {
        try {
            $response = Http::get(sprintf(self::SEARCH_ARTIST_URL, $artist));

            if (!$response->successful()) {
                return null;
            }

            return $response->json()['data'] ?? null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function searchAlbum(string $artist, string $album): ?array
    {
        try {
            $response = Http::get(sprintf(self::SEARCH_ALBUM_URL, $artist . ' ' . $album));

            if (!$response->successful()) {
                return null;
            }

            return $response->json()['data'] ?? null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function getNewReleases(int $artistId): ?array
    {
        $albums = $this->getAlbums($artistId);

        if (!$albums) {
            return null;
        }

        $timeframe = now()->subMonths(1);
        $newAlbums = [];

        foreach ($albums as $album) {
            $releaseDate = Carbon::createFromFormat('Y-m-d', $album['release_date']);

            if ($releaseDate->isAfter($timeframe)) {
                $newAlbums[] = $album;
            }
        }

        return $newAlbums;
    }

    public function getAlbums(int $artistId): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_ALBUMS_URL, $artistId));

            if (!$response->successful()) {
                return null;
            }

            return $response->json()['data'] ?? null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    private function getAlbum(int $albumId): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_ALBUM_URL, $albumId));

            if (!$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    private function getAlbumIdFromUrl(string $url): int
    {
        $path = parse_url($url, PHP_URL_PATH);
        return (int) substr($path, strrpos($path, '/') + 1);
    }
}
