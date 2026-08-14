<?php

use App\Api\Libraries\DTOs\MusicReleaseDTO;
use App\Api\Libraries\DTOs\MusicSearchResultDTO;
use App\Api\Libraries\Enums\MusicServiceEnum;
use App\Api\Libraries\Services\External\DiscogsService;
use Calliostro\Discogs\DiscogsApiClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;

covers(DiscogsService::class);

function discogsServiceWithJson(array $payload): DiscogsService
{
    $guzzle = Mockery::mock(GuzzleClient::class);
    $guzzle->shouldReceive('get')->once()->andReturn(
        new Response(200, [], json_encode($payload))
    );

    return new DiscogsService(new DiscogsApiClient($guzzle));
}

describe('DiscogsService', function () {
    it('maps a release payload into a MusicReleaseDTO', function () {
        $service = discogsServiceWithJson([
            'id' => 42,
            'title' => 'Violator',
            'uri' => 'https://www.discogs.com/release/42',
            'artists' => [['id' => 11, 'name' => 'Depeche Mode']],
            'images' => [['uri' => 'https://img.example/v.jpg']],
            'released' => '1990-03-19',
            'genres' => ['Electronic'],
            'record_type' => 'Album',
        ]);

        $dto = $service->importFromUrl('https://www.discogs.com/release/42');

        expect($dto)->toBeInstanceOf(MusicReleaseDTO::class);
        expect($dto->getId())->toBe(42);
        expect($dto->getTitle())->toBe('Violator');
        expect($dto->getArtist())->toBe('Depeche Mode');
        expect($dto->getArtistId())->toBe(11);
        expect($dto->getUrl())->toBe('https://www.discogs.com/release/42');
        expect($dto->getCover())->toBe('https://img.example/v.jpg');
        expect($dto->getReleaseDate()?->format('Y-m-d'))->toBe('1990-03-19');
        expect($dto->getGenres())->toBe(['Electronic']);
        expect($dto->getRecordType())->toBe('Album');
        expect($dto->getProvider())->toBe(MusicServiceEnum::DISCOGS->value);
    });

    it('strips parenthetical suffixes from artist names', function () {
        $service = discogsServiceWithJson([
            'id' => 7,
            'title' => 'X',
            'uri' => 'https://www.discogs.com/release/7',
            'artists' => [['id' => 3, 'name' => 'The Artist (2)']],
            'images' => [],
            'released' => '2020-01-01',
            'genres' => [],
        ]);

        $dto = $service->importFromUrl('https://www.discogs.com/release/7');

        expect($dto)->not->toBeNull();
        expect($dto->getArtist())->toBe('The Artist');
    });

    it('maps a search response into search result DTOs', function () {
        $guzzle = Mockery::mock(GuzzleClient::class);
        $guzzle->shouldReceive('get')->once()->andReturn(
            new Response(200, [], json_encode([
                'results' => [
                    ['id' => 1, 'title' => 'Some Album', 'cover_image' => 'https://img.example/a.jpg', 'year' => '1984'],
                ],
            ]))
        );

        $service = new DiscogsService(new DiscogsApiClient($guzzle));
        $results = $service->search('some album');

        expect($results)->toHaveCount(1);
        $result = $results[0];
        expect($result)->toBeInstanceOf(MusicSearchResultDTO::class);
        expect($result->getId())->toBe(1);
        expect($result->getTitle())->toBe('Some Album');
        expect($result->getCover())->toBe('https://img.example/a.jpg');
        expect($result->getReleaseYear())->toBe(1984);
        expect($result->getProvider())->toBe(MusicServiceEnum::DISCOGS->value);
        expect($result->getUrl())->toBe('https://www.discogs.com/release/1');
    });
});
