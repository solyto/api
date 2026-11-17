<?php

use App\Api\Libraries\Services\External\DeezerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function deezerAlbum(int $id, string $releaseDate): array
{
    return [
        'id' => $id,
        'title' => 'Album '.$id,
        'link' => 'https://www.deezer.com/album/'.$id,
        'cover_big' => null,
        'release_date' => $releaseDate,
    ];
}

describe('DeezerService::searchManyArtists', function () {
    it('sends one search per artist and maps the results back to each artist', function () {
        Http::fake([
            'https://api.deezer.com/search/artist?q=Radiohead' => Http::response(['data' => [['id' => 399]]]),
            'https://api.deezer.com/search/artist?q=Portishead' => Http::response(['data' => [['id' => 1171]]]),
            'https://api.deezer.com/search/artist?q=Nobody' => Http::response(['data' => []]),
        ]);

        $results = app(DeezerService::class)->searchManyArtists(['Radiohead', 'Portishead', 'Nobody']);

        expect($results)->toBe([0 => [['id' => 399]], 1 => [['id' => 1171]], 2 => []]);
        Http::assertSentCount(3);
    });

    it('returns what the single search returns for each artist', function () {
        Http::fake(['*' => Http::response(['data' => [['id' => 399], ['id' => 7]]])]);

        $service = app(DeezerService::class);

        expect($service->searchManyArtists(['Radiohead']))->toBe([$service->searchArtists('Radiohead')]);
    });

    it('yields null only for the artists whose search failed', function () {
        Http::fake([
            'https://api.deezer.com/search/artist?q=Broken' => Http::response('', 500),
            'https://api.deezer.com/search/artist?q=Throttled' => Http::response('', 429),
            'https://api.deezer.com/search/artist?q=Offline' => Http::failedConnection(),
            '*' => Http::response(['data' => [['id' => 399]]]),
        ]);

        $results = app(DeezerService::class)->searchManyArtists(['Broken', 'Radiohead', 'Throttled', 'Offline']);

        expect($results)->toBe([0 => null, 1 => [['id' => 399]], 2 => null, 3 => null]);
    });

    it('searches every artist when there are more than fit in one pool round', function () {
        Http::fake(fn (Request $request) => Http::response(['data' => [['id' => (int) substr($request->url(), -2)]]]));

        $artists = array_map(fn (int $i) => sprintf('Artist %02d', $i), range(1, 12));

        $results = app(DeezerService::class)->searchManyArtists($artists);

        Http::assertSentCount(12);
        expect(array_map(fn (array $search) => $search[0]['id'], $results))->toBe(range(1, 12));
    });
});

describe('DeezerService::getNewReleasesForArtists', function () {
    it('fetches the albums of each artist once and keeps only last month\'s releases', function () {
        Http::fake([
            'https://api.deezer.com/artist/399/albums' => Http::response(['data' => [
                deezerAlbum(1, now()->subDays(3)->toDateString()),
                deezerAlbum(2, now()->subYear()->toDateString()),
            ]]),
            'https://api.deezer.com/artist/1171/albums' => Http::response(['data' => [deezerAlbum(3, now()->subYear()->toDateString())]]),
            'https://api.deezer.com/artist/5/albums' => Http::response(['data' => []]),
        ]);

        $releases = app(DeezerService::class)->getNewReleasesForArtists([399, 1171, 399, 5]);

        expect(array_keys($releases))->toBe([399, 1171, 5]);
        expect(array_column($releases[399], 'id'))->toBe([1]);
        expect($releases[1171])->toBe([]);
        expect($releases[5])->toBeNull();
        Http::assertSentCount(3);
    });

    it('returns what the single lookup returns for each artist', function () {
        Http::fake(['*' => Http::response(['data' => [
            deezerAlbum(1, now()->subDays(3)->toDateString()),
            deezerAlbum(2, now()->subYear()->toDateString()),
        ]])]);

        $service = app(DeezerService::class);

        expect($service->getNewReleasesForArtists([399]))->toBe([399 => $service->getNewReleases(399)]);
    });

    it('yields null only for the artists whose request failed', function () {
        Http::fake([
            'https://api.deezer.com/artist/1/albums' => Http::response('', 500),
            'https://api.deezer.com/artist/2/albums' => Http::response('', 429),
            'https://api.deezer.com/artist/3/albums' => Http::failedConnection(),
            '*' => Http::response(['data' => [deezerAlbum(10, now()->toDateString())]]),
        ]);

        $releases = app(DeezerService::class)->getNewReleasesForArtists([1, 399, 2, 3]);

        expect($releases[1])->toBeNull();
        expect(array_column($releases[399], 'id'))->toBe([10]);
        expect($releases[2])->toBeNull();
        expect($releases[3])->toBeNull();
    });

    it('requests every artist when there are more than fit in one pool round', function () {
        Http::fake(fn (Request $request) => Http::response(['data' => [
            deezerAlbum((int) explode('/', $request->url())[4], now()->toDateString()),
        ]]));

        $releases = app(DeezerService::class)->getNewReleasesForArtists(range(1, 12));

        Http::assertSentCount(12);
        expect(array_map(fn (array $albums) => $albums[0]['id'], $releases))->toBe(array_combine(range(1, 12), range(1, 12)));
    });
});
