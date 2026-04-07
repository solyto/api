<?php

namespace App\Api\Libraries\Services\External;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class DeezerApiService
{
    private const SEARCH_ARTIST_URL = 'https://api.deezer.com/search/artist?q=%s';

    private const SEARCH_ALBUM_URL = 'https://api.deezer.com/search/album?q=%s';

    private const GET_ALBUMS_URL = 'https://api.deezer.com/artist/%d/albums';

    private const GET_ALBUM_URL = 'https://api.deezer.com/album/%d';

    public function searchArtists(string $artist): ?array
    {
        try {
            $response = Http::get(sprintf(self::SEARCH_ARTIST_URL, $artist));

            if (! $response->successful()) {
                return null;
            }

            return $response->json()['data'] ?? null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function searchAlbum(string $artist, string $album)
    {
        try {
            $response = Http::get(sprintf(self::SEARCH_ALBUM_URL, $artist.' '.$album));

            if (! $response->successful()) {
                return null;
            }

            return $response->json()['data'] ?? null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function getAlbums(int $artistId): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_ALBUMS_URL, $artistId));

            if (! $response->successful()) {
                return null;
            }

            return $response->json()['data'] ?? null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function getAlbum(int $albumId): ?array
    {
        try {
            $response = Http::get(sprintf(self::GET_ALBUM_URL, $albumId));

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    public function getNewReleases(int $artistId): ?array
    {
        $albums = $this->getAlbums($artistId);

        if (! $albums) {
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
}
