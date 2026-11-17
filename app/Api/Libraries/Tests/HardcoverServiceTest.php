<?php

use App\Api\Libraries\Services\External\HardcoverService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function hardcoverBook(int $id, string $releaseDate): array
{
    return [
        'id' => $id,
        'slug' => 'book-'.$id,
        'title' => 'Book '.$id,
        'release_date' => $releaseDate,
        'description' => null,
        'pages' => 100,
        'image' => null,
        'contributions' => [],
    ];
}

describe('HardcoverService::getNewReleasesForAuthors', function () {
    beforeEach(function () {
        config(['services.hardcover.api_key' => 'test-key']);
    });

    it('sends one request per author and maps the releases back to each author', function () {
        Http::fake(fn (Request $request) => match ($request['variables']['authorName']) {
            'Frank Herbert' => Http::response(['data' => ['books' => [
                hardcoverBook(1, now()->subMonth()->toDateString()),
                hardcoverBook(2, now()->subYears(2)->toDateString()),
            ]]]),
            'Ursula K. Le Guin' => Http::response(['data' => ['books' => [hardcoverBook(3, now()->addMonth()->toDateString())]]]),
            'Nobody' => Http::response(['data' => ['books' => []]]),
        });

        $releases = app(HardcoverService::class)->getNewReleasesForAuthors(['Frank Herbert', 'Ursula K. Le Guin', 'Nobody']);

        expect(array_keys($releases))->toBe([0, 1, 2]);
        expect(array_column($releases[0], 'id'))->toBe([1]);
        expect(array_column($releases[1], 'id'))->toBe([3]);
        expect($releases[2])->toBeNull();

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.hardcover.app/v1/graphql'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['variables'] === ['authorName' => 'Frank Herbert']);
    });

    it('returns what the single lookup returns for each author', function () {
        Http::fake(fn () => Http::response(['data' => ['books' => [
            hardcoverBook(1, now()->subMonth()->toDateString()),
            hardcoverBook(2, now()->addYears(2)->toDateString()),
        ]]]));

        $service = app(HardcoverService::class);

        expect($service->getNewReleasesForAuthors(['Frank Herbert']))->toBe([$service->getNewReleases('Frank Herbert')]);
    });

    it('yields null only for the authors whose request failed', function () {
        Http::fake(fn (Request $request) => match ($request['variables']['authorName']) {
            'Server Error' => Http::response('', 500),
            'Rate Limited' => Http::response('', 429),
            'Unreachable' => Http::failedConnection(),
            default => Http::response(['data' => ['books' => [hardcoverBook(1, now()->toDateString())]]]),
        });

        $releases = app(HardcoverService::class)->getNewReleasesForAuthors(['Server Error', 'Frank Herbert', 'Rate Limited', 'Unreachable']);

        expect($releases[0])->toBeNull();
        expect(array_column($releases[1], 'id'))->toBe([1]);
        expect($releases[2])->toBeNull();
        expect($releases[3])->toBeNull();
    });

    it('requests every author when there are more than fit in one pool round', function () {
        Http::fake(fn (Request $request) => Http::response(['data' => ['books' => [
            hardcoverBook((int) substr($request['variables']['authorName'], 7), now()->toDateString()),
        ]]]));

        $authors = array_map(fn (int $i) => 'Author '.$i, range(1, 12));

        $releases = app(HardcoverService::class)->getNewReleasesForAuthors($authors);

        Http::assertSentCount(12);
        expect(array_map(fn (array $books) => $books[0]['id'], $releases))->toBe(range(1, 12));
    });
});
