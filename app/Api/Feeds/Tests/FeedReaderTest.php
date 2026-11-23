<?php

use App\Api\Feeds\Services\FeedReader;
use App\Shared\Services\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;

covers(FeedReader::class);

function feedReaderWithFixture(string $fixture): FeedReader
{
    Http::fake(['fixture.example/*' => Http::response(
        file_get_contents(base_path('tests/Fixtures/'.$fixture)),
        200,
        ['Content-Type' => 'application/rss+xml']
    )]);

    return app(FeedReader::class);
}

function fakeAngak(): void
{
    Http::fake([
        'https://angak.fr/actualites/' => Http::response(
            file_get_contents(base_path('tests/Fixtures/angak_actualites.html')),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        ),
        'https://angak.fr/feed/' => Http::response(
            file_get_contents(base_path('tests/Fixtures/feed_empty.rss')),
            200,
            ['Content-Type' => 'application/rss+xml; charset=UTF-8']
        ),
        'https://angak.fr/comments/feed/' => Http::response(
            file_get_contents(base_path('tests/Fixtures/feed.atom')),
            200,
            ['Content-Type' => 'application/atom+xml; charset=UTF-8']
        ),
        'https://angak.fr/actualites/feed/' => Http::response(
            file_get_contents(base_path('tests/Fixtures/feed.rss')),
            200,
            ['Content-Type' => 'application/rss+xml; charset=UTF-8']
        ),
        '*' => Http::response('Not found', 404),
    ]);
}

// A single WordPress post links the site feed first, then the post's own comments feed under the post's path.
function fakeWordPressPost(string $siteFeed): void
{
    $comments = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
        .'<title>Comments on: Some post</title><link>https://blog.example/2026/11/some-post/</link><description></description>'
        .'<item><title>By: Someone</title><link>https://blog.example/2026/11/some-post/#comment-1</link><description>Nice</description></item>'
        .'</channel></rss>';

    Http::fake([
        'https://blog.example/2026/11/some-post/feed/' => Http::response($comments, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']),
        'https://blog.example/2026/11/some-post/' => Http::response(
            '<!DOCTYPE html><html><head><title>Some post</title>'
            .'<link rel="alternate" type="application/rss+xml" title="Blog &raquo; Feed" href="https://blog.example/feed/" />'
            .'<link rel="alternate" type="application/rss+xml" title="Blog &raquo; Some post Comments Feed" href="https://blog.example/2026/11/some-post/feed/" />'
            .'</head><body><h1>Some post</h1></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        ),
        'https://blog.example/feed/' => Http::response(
            file_get_contents(base_path('tests/Fixtures/'.$siteFeed)),
            200,
            ['Content-Type' => 'application/rss+xml; charset=UTF-8']
        ),
        '*' => Http::response('Not found', 404),
    ]);
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

    it('parses already downloaded content into feed data', function () {
        $data = app(FeedReader::class)->parseFeedData(file_get_contents(base_path('tests/Fixtures/feed.rss')));

        expect($data)->toBeArray();
        expect($data['title'])->toBe('Fixture Feed');
        expect($data['items'])->toHaveCount(2);
    });

    it('returns false for downloaded content that is not a feed', function () {
        $reader = app(FeedReader::class);

        expect($reader->parseFeedData('<!DOCTYPE html><html><body><p>Hello</p></body></html>'))->toBeFalse();
        expect($reader->parseFeedData(file_get_contents(base_path('tests/Fixtures/feed_empty.rss'))))->toBeFalse();
    });

    it('resolves an autodiscovered feed link against the URL after redirects', function () {
        Http::fake([
            'https://site.example/blog' => Http::response('', 301, ['Location' => '/blog/']),
            'https://site.example/blog/' => Http::response(
                '<!DOCTYPE html><html><head><link rel="alternate" type="application/rss+xml" href="feed.xml"></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://site.example/blog/feed.xml' => Http::response(
                file_get_contents(base_path('tests/Fixtures/feed.rss')),
                200,
                ['Content-Type' => 'application/rss+xml']
            ),
            '*' => Http::response('Not found', 404),
        ]);

        $data = app(FeedReader::class)->getFeedData('https://site.example/blog');

        expect($data['title'])->toBe('Fixture Feed');
        expect($data['url'])->toBe('https://site.example/blog/feed.xml');
        Http::assertSent(fn ($request) => $request->url() === 'https://site.example/blog/feed.xml');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://site.example/feed.xml');
        Http::assertSentCount(3);
    });

    it('picks the discovered feed under the page path when the first one is empty', function () {
        fakeAngak();

        $data = app(FeedReader::class)->getFeedData('https://angak.fr/actualites/');

        expect($data['url'])->toBe('https://angak.fr/actualites/feed/');
        expect($data['title'])->toBe('Fixture Feed');
        expect($data['items'])->toHaveCount(2);
    });

    it('keeps the site feed for a WordPress post page, not the post\'s comments', function () {
        fakeWordPressPost(siteFeed: 'feed.rss');

        $data = app(FeedReader::class)->getFeedData('https://blog.example/2026/11/some-post/');

        expect($data['url'])->toBe('https://blog.example/feed/')
            ->and($data['title'])->toBe('Fixture Feed');
    });

    it('never falls back to a comments feed, even when the site feed is empty', function () {
        fakeWordPressPost(siteFeed: 'feed_empty.rss');

        expect(app(FeedReader::class)->getFeedData('https://blog.example/2026/11/some-post/'))->toBeFalse();
    });

    it('returns the URL of a page\'s single discovered feed', function () {
        Http::fake([
            'https://single.example/news/' => Http::response(
                '<!DOCTYPE html><html><head><link rel="alternate" type="application/rss+xml" href="https://single.example/rss.xml"></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://single.example/rss.xml' => Http::response(
                file_get_contents(base_path('tests/Fixtures/feed.rss')),
                200,
                ['Content-Type' => 'application/rss+xml']
            ),
            '*' => Http::response('Not found', 404),
        ]);

        $data = app(FeedReader::class)->getFeedData('https://single.example/news/');

        expect($data['url'])->toBe('https://single.example/rss.xml');
        expect($data['items'])->toHaveCount(2);
        Http::assertSentCount(2);
    });

    it('returns a feed URL as its own url', function () {
        $data = feedReaderWithFixture('feed.rss')->getFeedData('https://fixture.example/feed.rss');

        expect($data['url'])->toBe('https://fixture.example/feed.rss');
        Http::assertSentCount(1);
    });

    it('never fetches a discovered feed on a blocked address', function () {
        app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(
            fn (string $host) => $host === 'internal.example' ? ['10.0.0.1'] : ['93.184.216.34']
        ));
        Http::fake([
            'https://public.example/news/' => Http::response(
                '<!DOCTYPE html><html><head>'
                .'<link rel="alternate" type="application/rss+xml" href="https://internal.example/news/feed/">'
                .'<link rel="alternate" type="application/rss+xml" href="https://public.example/feed/">'
                .'</head><body></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://public.example/feed/' => Http::response(
                file_get_contents(base_path('tests/Fixtures/feed.rss')),
                200,
                ['Content-Type' => 'application/rss+xml']
            ),
            '*' => Http::response(file_get_contents(base_path('tests/Fixtures/feed.atom')), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $data = app(FeedReader::class)->getFeedData('https://public.example/news/');

        expect($data['url'])->toBe('https://public.example/feed/');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'internal.example'));
    });

    it('parses already downloaded content in the charset of its Content-Type', function () {
        $rss = mb_convert_encoding(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Café “News” €</title><link>https://cp1252.example/</link>'
            .'<item><title>Eins</title><link>https://cp1252.example/1</link></item></channel></rss>',
            'Windows-1252',
            'UTF-8'
        );

        expect(app(FeedReader::class)->parseFeedData($rss, 'application/rss+xml; charset=windows-1252')['title'])->toBe('Café “News” €');
    });

    it('keeps parsed content out of later fetches', function () {
        $reader = feedReaderWithFixture('feed.atom');

        $reader->parseFeedData(file_get_contents(base_path('tests/Fixtures/feed.rss')));

        expect($reader->getFeedData('https://fixture.example/feed.atom')['items'][0]->get_title())->toBe('Atom Post');
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

    it('prefers the content:encoded image over a tracking pixel in the description', function () {
        $reader = feedReaderWithFixture('feed_golem.rss');
        $items = $reader->getItems('https://fixture.example/feed_golem.rss');

        expect($reader->extractImageUrl($items[0]))->toBe('https://www.golem.de/2610/214715-606214-606212_rc.jpg');
    });

    it('never uses a tracking pixel and falls back to og:image instead', function () {
        Http::fake([
            'www.golem.de/news/article-2.html' => Http::response('<meta property="og:image" content="https://www.golem.de/2610/214718-og.jpg">'),
        ]);
        $reader = feedReaderWithFixture('feed_golem.rss');
        $items = $reader->getItems('https://fixture.example/feed_golem.rss');

        expect($reader->extractImageUrl($items[1]))->toBe('https://www.golem.de/2610/214718-og.jpg');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cpx.golem.de'));
    });

    it('skips images from known tracker hosts and decodes the image url', function () {
        $reader = feedReaderWithFixture('feed_golem.rss');
        $items = $reader->getItems('https://fixture.example/feed_golem.rss');

        expect($reader->extractImageUrl($items[2]))->toBe('https://www.golem.de/2610/214720-1_rc.jpg?w=416&h=234');
    });
});
