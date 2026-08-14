<?php

use App\Api\Feeds\Services\FeedReader;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SimplePie\SimplePie;

covers(FeedReader::class);

function feedReaderWithFixture(string $fixture): FeedReader
{
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->andReturnUsing(function (RequestInterface $request) use ($fixture) {
        return new Response(
            200,
            ['Content-Type' => 'application/rss+xml'],
            file_get_contents(__DIR__.'/../../../../tests/Fixtures/'.$fixture)
        );
    });

    $factory = new HttpFactory;
    $pie = new SimplePie;
    $pie->enable_cache(false);
    $pie->set_http_client($client, $factory, $factory);

    return new FeedReader($pie);
}

describe('FeedReader', function () {
    it('parses an RSS fixture into items', function () {
        $reader = feedReaderWithFixture('feed.rss');

        $items = $reader->getItems('https://fixture.example/feed.rss');

        expect($items)->toBeArray();
        expect($items)->toHaveCount(2);
        expect($items[0]->get_title())->toBe('First Post');
        expect($items[1]->get_title())->toBe('Second Post');
    });

    it('parses an Atom fixture into items', function () {
        $reader = feedReaderWithFixture('feed.atom');

        $items = $reader->getItems('https://fixture.example/feed.atom');

        expect($items)->toBeArray();
        expect($items)->toHaveCount(1);
        expect($items[0]->get_title())->toBe('Atom Post');
    });

    it('returns feed data with title and items', function () {
        $reader = feedReaderWithFixture('feed.rss');

        $data = $reader->getFeedData('https://fixture.example/feed.rss');

        expect($data)->toBeArray();
        expect($data['title'])->toBe('Fixture Feed');
        expect($data['items'])->toHaveCount(2);
    });

    it('returns false when the feed has no items', function () {
        $reader = feedReaderWithFixture('feed_empty.rss');

        expect($reader->getItems('https://fixture.example/empty.rss'))->toBeFalse();
        expect($reader->getFeedData('https://fixture.example/empty.rss'))->toBeFalse();
    });

    it('extracts a media thumbnail from an item', function () {
        $reader = feedReaderWithFixture('feed.rss');
        $items = $reader->getItems('https://fixture.example/feed.rss');

        expect($reader->extractImageUrl($items[0]))->toBe('https://cdn.example/thumb.jpg');
    });

    it('extracts a media content image from an item', function () {
        $reader = feedReaderWithFixture('feed.rss');
        $items = $reader->getItems('https://fixture.example/feed.rss');

        expect($reader->extractImageUrl($items[1]))->toBe('https://cdn.example/media.jpg');
    });
});
