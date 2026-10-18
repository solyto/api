<?php

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Services\FeedReader;
use App\Api\Feeds\Services\FeedService;

function feedItemDouble(?string $description, string $id = 'https://example.com/post-1'): \Mockery\MockInterface
{
    $item = Mockery::mock(\SimplePie\Item::class);
    $item->shouldReceive('get_link')->andReturn($id);
    $item->shouldReceive('get_id')->andReturn($id);
    $item->shouldReceive('get_title')->andReturn('A post');
    $item->shouldReceive('get_date')->andReturn('1756730400');
    $item->shouldReceive('get_description')->andReturn($description);

    return $item;
}

describe('FeedService', function () {
    it('truncates oversized descriptions on sync', function () {
        $feed = Feed::factory()->create();

        $reader = $this->mock(FeedReader::class);
        $reader->shouldReceive('getItems')->once()->andReturn([feedItemDouble(str_repeat('a', 5000))]);
        $reader->shouldReceive('extractImageUrl')->andReturn(null);

        app(FeedService::class)->syncFeed($feed->id);

        $stored = FeedItem::sole();
        // Str::limit cuts at 1000 chars and appends an ellipsis
        expect($stored->description)->toBe(str_repeat('a', 1000).'...');
    });

    it('keeps short descriptions untouched and tolerates a missing one', function () {
        $feed = Feed::factory()->create();

        $reader = $this->mock(FeedReader::class);
        $reader->shouldReceive('getItems')->once()->andReturn([
            feedItemDouble('<p>short</p>'),
            feedItemDouble(null, 'https://example.com/post-2'),
        ]);
        $reader->shouldReceive('extractImageUrl')->andReturn(null);

        app(FeedService::class)->syncFeed($feed->id);

        $items = FeedItem::orderBy('feed_item_id')->get();
        expect($items[0]->description)->toBe('<p>short</p>');
        expect($items[1]->description)->toBeNull();
    });

    it('stores overlong item ids as a stable hash so the sync does not fail', function () {
        $feed = Feed::factory()->create();
        // Google News GUIDs are 600+ chars, more than the varchar(255) column holds
        $longId = 'CBMi'.str_repeat('x', 614);

        $reader = $this->mock(FeedReader::class);
        $reader->shouldReceive('getItems')->twice()->andReturn([
            feedItemDouble('<p>news</p>', $longId),
            feedItemDouble('<p>short</p>'),
        ]);
        $reader->shouldReceive('extractImageUrl')->andReturn(null);

        app(FeedService::class)->syncFeed($feed->id);
        app(FeedService::class)->syncFeed($feed->id);

        $ids = FeedItem::pluck('feed_item_id')->sort()->values()->all();
        expect($ids)->toBe(['https://example.com/post-1', 'sha1:'.sha1($longId)]);
    });
});
