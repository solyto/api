<?php

namespace App\Api\Libraries\Services\External;

use App\Api\Libraries\DTOs\MusicReleaseDTO;
use App\Api\Libraries\DTOs\MusicSearchResultDTO;
use App\Api\Libraries\Enums\MusicServiceEnum;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SpotifyService
{
    private const string TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const string SEARCH_URL = 'https://api.spotify.com/v1/search';

    private const string GET_ALBUMS_URL = 'https://api.spotify.com/v1/artists/%s/albums';

    private const string GET_ALBUM_URL = 'https://api.spotify.com/v1/albums/%s';

    private const string ALBUM_URL = 'https://open.spotify.com/album/%s';

    private const string TOKEN_CACHE_KEY = 'spotify_access_token';

    // Spotify client-credentials tokens expire after 3600s; cache for ~55
    // minutes so the cached token is still valid when it is reused.
    private const int TOKEN_CACHE_TTL = 3300;

    public function importFromUrl(string $url): ?MusicReleaseDTO
    {
        $albumId = $this->getAlbumIdFromUrl($url);

        if (! $albumId) {
            return null;
        }

        $result = $this->get(sprintf(self::GET_ALBUM_URL, $albumId));

        if (! $result) {
            return null;
        }

        return new MusicReleaseDTO(
            id: $result['id'],
            artist: $result['artists'][0]['name'] ?? null,
            artistId: $result['artists'][0]['id'] ?? null,
            title: $result['name'],
            url: sprintf(self::ALBUM_URL, $result['id']),
            cover: $result['images'][0]['url'] ?? null,
            provider: MusicServiceEnum::SPOTIFY->value,
            releaseDate: self::parseReleaseDate($result['release_date'] ?? null, $result['release_date_precision'] ?? null),
            genres: $result['genres'] ?? [],
            recordType: $result['album_type'] ?? null,
        );
    }

    public function searchArtists(string $artist): ?array
    {
        $result = $this->get(self::SEARCH_URL, ['q' => $artist, 'type' => 'artist', 'limit' => 5]);

        return $result['artists']['items'] ?? null;
    }

    public function searchAlbum(string $query): ?array
    {
        $result = $this->get(self::SEARCH_URL, ['q' => $query, 'type' => 'album', 'limit' => 20]);

        $items = $result['albums']['items'] ?? null;

        if (! is_array($items)) {
            return null;
        }

        return array_map(fn ($item) => new MusicSearchResultDTO(
            id: $item['id'],
            title: $item['name'],
            artist: $item['artists'][0]['name'] ?? null,
            cover: $item['images'][0]['url'] ?? null,
            releaseYear: isset($item['release_date']) ? (int) substr($item['release_date'], 0, 4) : null,
            provider: MusicServiceEnum::SPOTIFY->value,
            url: sprintf(self::ALBUM_URL, $item['id']),
        ), $items);
    }

    public function getNewReleases(string $artistId): ?array
    {
        $albums = $this->getAlbums($artistId);

        if (! $albums) {
            return null;
        }

        $timeframe = now()->subMonths(1);
        $newAlbums = [];

        foreach ($albums as $album) {
            $releaseDate = self::parseReleaseDate($album['release_date'] ?? null, $album['release_date_precision'] ?? null);

            if ($releaseDate && $releaseDate->isAfter($timeframe)) {
                $newAlbums[] = $album;
            }
        }

        return $newAlbums;
    }

    public function getAlbums(string $artistId): ?array
    {
        $result = $this->get(sprintf(self::GET_ALBUMS_URL, $artistId), [
            'include_groups' => 'album,single',
            'limit' => 50,
        ]);

        return $result['items'] ?? null;
    }

    /**
     * Parse a Spotify release date honouring its precision. Day-precision
     * dates use "Y-m-d"; month-precision dates are padded to the first of
     * the month and year-precision dates to January 1st.
     */
    public static function parseReleaseDate(?string $date, ?string $precision): ?Carbon
    {
        if (! $date) {
            return null;
        }

        try {
            return match ($precision) {
                'year' => Carbon::createFromFormat('Y-m-d', $date.'-01-01'),
                'month' => Carbon::createFromFormat('Y-m-d', $date.'-01'),
                default => Carbon::createFromFormat('Y-m-d', $date),
            };
        } catch (\Exception) {
            return null;
        }
    }

    private function get(string $url, array $query = []): ?array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return null;
        }

        try {
            $response = Http::withToken($token)->get($url, $query);

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException) {
            return null;
        }
    }

    private function getAccessToken(): ?string
    {
        $token = Cache::get(self::TOKEN_CACHE_KEY);

        if ($token) {
            return $token;
        }

        $clientId = config('services.spotify.client_id');
        $clientSecret = config('services.spotify.client_secret');

        if (! $clientId || ! $clientSecret) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $token = $response->json('access_token');

        if (! $token) {
            return null;
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_CACHE_TTL);

        return $token;
    }

    private function getAlbumIdFromUrl(string $url): ?string
    {
        if (preg_match('#/album/([A-Za-z0-9]+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
