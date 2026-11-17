<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\MusicReleaseDTO;
use App\Api\Libraries\DTOs\MusicSearchResultDTO;
use App\Api\Libraries\Enums\MusicServiceEnum;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DeezerService
{
    private const string SEARCH_ARTIST_URL = 'https://api.deezer.com/search/artist?q=%s';
    private const string SEARCH_ALBUM_URL = 'https://api.deezer.com/search/album?q=%s';
    private const string GET_ALBUMS_URL = 'https://api.deezer.com/artist/%d/albums';
    private const string GET_ALBUM_URL = 'https://api.deezer.com/album/%d';
    private const int POOL_SIZE = 5;

    public function importFromUrl(string $url): ?MusicReleaseDTO
    {
        $albumId = $this->getAlbumIdFromUrl($url);
        $result = $this->getAlbum($albumId);

        if (!$result) {
            return null;
        }

        return new MusicReleaseDTO(
            id: $result['id'],
            artist: $result['artist']['name'],
            artistId: $result['artist']['id'],
            title: $result['title'],
            url: $result['link'],
            cover: $result['cover_big'],
            provider: MusicServiceEnum::DEEZER->value,
            releaseDate: Carbon::createFromFormat('Y-m-d', $result['release_date']),
            genres: array_map(fn($genre) => $genre['name'], $result['genres']['data']),
            recordType: $result['record_type'] ?? null,
        );
    }

    public function searchArtists(string $artist): ?array
    {
        try {
            return $this->parseData(Http::get(sprintf(self::SEARCH_ARTIST_URL, $artist)));
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * @param  array<array-key, string>  $artists
     * @return array<array-key, ?array>
     */
    public function searchManyArtists(array $artists): array
    {
        return $this->getManyData(array_map(fn(string $artist) => sprintf(self::SEARCH_ARTIST_URL, $artist), $artists));
    }

    public function searchAlbum(string $query): ?array
    {
        try {
            $response = Http::get(sprintf(self::SEARCH_ALBUM_URL, $query));

            if (!$response->successful()) {
                return null;
            }

            $items = $response->json()['data'] ?? null;

            if (!is_array($items)) {
                return null;
            }

            return array_map(fn($item) => new MusicSearchResultDTO(
                id: (int) $item['id'],
                title: $item['title'],
                artist: $item['artist']['name'] ?? null,
                cover: $item['cover_big'] ?? null,
                releaseYear: isset($item['release_date']) ? (int) substr($item['release_date'], 0, 4) : null,
                provider: MusicServiceEnum::DEEZER->value,
                url: $item['link'],
            ), $items);
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function getNewReleases(int $artistId): ?array
    {
        return $this->filterNewReleases($this->getAlbums($artistId));
    }

    /**
     * @param  array<array-key, int>  $artistIds
     * @return array<int, ?array>
     */
    public function getNewReleasesForArtists(array $artistIds): array
    {
        $urls = [];

        foreach (array_unique($artistIds) as $artistId) {
            $urls[$artistId] = sprintf(self::GET_ALBUMS_URL, $artistId);
        }

        return array_map(fn(?array $albums) => $this->filterNewReleases($albums), $this->getManyData($urls));
    }

    public function getAlbums(int $artistId): ?array
    {
        try {
            return $this->parseData(Http::get(sprintf(self::GET_ALBUMS_URL, $artistId)));
        } catch (ConnectionException $e) {
            return null;
        }
    }

    private function filterNewReleases(?array $albums): ?array
    {
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

    /**
     * @param  array<array-key, string>  $urls
     * @return array<array-key, ?array>
     */
    private function getManyData(array $urls): array
    {
        $responses = Http::pool(function (Pool $pool) use ($urls) {
            foreach ($urls as $key => $url) {
                $pool->as((string) $key)->get($url);
            }
        }, self::POOL_SIZE);

        $data = [];

        foreach ($urls as $key => $url) {
            $response = $responses[$key] ?? null;
            $data[$key] = $response instanceof Response ? $this->parseData($response) : null;
        }

        return $data;
    }

    private function parseData(Response $response): ?array
    {
        if (!$response->successful()) {
            return null;
        }

        return $response->json()['data'] ?? null;
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
