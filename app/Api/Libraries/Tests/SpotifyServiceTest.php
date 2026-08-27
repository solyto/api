<?php

use App\Api\Libraries\Enums\MusicServiceEnum;
use App\Api\Libraries\Services\External\SpotifyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

describe('SpotifyService', function () {
    beforeEach(function () {
        config([
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
        ]);
        Cache::flush();
    });

    it('searches albums', function () {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok123', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://api.spotify.com/v1/search*' => Http::response([
                'albums' => ['items' => [[
                    'id' => '4aawyAB9vmqN3uQ7FjRGTy',
                    'name' => 'OK Computer',
                    'artists' => [['id' => '5K4W6rqBFWDnAN6FQUkS6x', 'name' => 'Radiohead']],
                    'images' => [['url' => 'https://cover.example/ok.jpg']],
                    'release_date' => '1997-05-21',
                    'release_date_precision' => 'day',
                ]]],
            ]),
        ]);

        $results = app(SpotifyService::class)->searchAlbum('OK Computer');

        expect($results)->toHaveCount(1);
        expect($results[0]->getTitle())->toBe('OK Computer');
        expect($results[0]->getArtist())->toBe('Radiohead');
        expect($results[0]->getReleaseYear())->toBe(1997);
        expect($results[0]->getProvider())->toBe(MusicServiceEnum::SPOTIFY->value);
        expect($results[0]->getId())->toBe('4aawyAB9vmqN3uQ7FjRGTy');
        expect($results[0]->getUrl())->toBe('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy');
    });

    it('imports an album from a spotify url', function () {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok123', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://api.spotify.com/v1/albums/4aawyAB9vmqN3uQ7FjRGTy' => Http::response([
                'id' => '4aawyAB9vmqN3uQ7FjRGTy',
                'name' => 'OK Computer',
                'artists' => [['id' => '5K4W6rqBFWDnAN6FQUkS6x', 'name' => 'Radiohead']],
                'images' => [['url' => 'https://cover.example/ok.jpg']],
                'release_date' => '1997-05-21',
                'release_date_precision' => 'day',
                'album_type' => 'album',
                'genres' => ['alternative rock'],
            ]),
        ]);

        $dto = app(SpotifyService::class)->importFromUrl('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy');

        expect($dto)->not->toBeNull();
        expect($dto->getTitle())->toBe('OK Computer');
        expect($dto->getArtist())->toBe('Radiohead');
        expect($dto->getArtistId())->toBe('5K4W6rqBFWDnAN6FQUkS6x');
        expect($dto->getGenres())->toBe(['alternative rock']);
        expect($dto->getRecordType())->toBe('album');
        expect($dto->getProvider())->toBe(MusicServiceEnum::SPOTIFY->value);
        expect($dto->getReleaseDate()?->toDateString())->toBe('1997-05-21');
    });

    it('searches artists and collects new releases', function () {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok123', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://api.spotify.com/v1/search*' => Http::response([
                'artists' => ['items' => [['id' => '5K4W6rqBFWDnAN6FQUkS6x', 'name' => 'Radiohead']]],
            ]),
            'https://api.spotify.com/v1/artists/5K4W6rqBFWDnAN6FQUkS6x/albums*' => Http::response([
                'items' => [
                    ['id' => 'new1', 'name' => 'Fresh', 'release_date' => now()->toDateString(), 'release_date_precision' => 'day'],
                    ['id' => 'old1', 'name' => 'Old', 'release_date' => now()->subMonths(3)->toDateString(), 'release_date_precision' => 'day'],
                ],
            ]),
        ]);

        $artists = app(SpotifyService::class)->searchArtists('Radiohead');

        expect($artists)->toHaveCount(1);
        expect($artists[0]['id'])->toBe('5K4W6rqBFWDnAN6FQUkS6x');

        $newReleases = app(SpotifyService::class)->getNewReleases('5K4W6rqBFWDnAN6FQUkS6x');

        expect($newReleases)->toHaveCount(1);
        expect($newReleases[0]['id'])->toBe('new1');
    });

    it('fetches the oauth token once and reuses the cached token', function () {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok123', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://api.spotify.com/v1/search*' => Http::response(['artists' => ['items' => []]]),
        ]);

        app(SpotifyService::class)->searchArtists('Radiohead');
        app(SpotifyService::class)->searchArtists('Radiohead');

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://accounts.spotify.com/api/token');

        expect(Cache::get('spotify_access_token'))->toBe('tok123');
    });

    it('returns null on failure', function () {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok123', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://api.spotify.com/v1/search*' => Http::response([], 500),
        ]);

        expect(app(SpotifyService::class)->searchAlbum('x'))->toBeNull();
    });

    it('returns null without configured credentials', function () {
        config(['services.spotify.client_id' => null, 'services.spotify.client_secret' => null]);

        expect(app(SpotifyService::class)->searchAlbum('x'))->toBeNull();
    });
});
