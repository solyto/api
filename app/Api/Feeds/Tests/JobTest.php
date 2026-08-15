<?php

use App\Api\Feeds\Jobs\DeleteOldFeedItems;
use App\Api\Feeds\Jobs\SyncFeed;
use App\Api\Feeds\Jobs\SyncFeeds;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Feeds\Services\FeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

describe('SyncFeed', function () {
    it('syncs the feed through the service', function () {
        $feed = Feed::factory()->create();

        $service = $this->mock(FeedService::class);
        $service->shouldReceive('syncFeed')->with($feed->id)->once()->andReturn(true);

        app(SyncFeed::class, ['feedId' => $feed->id])->handle($service);
    });

    it('records a failure when the sync throws', function () {
        $feed = Feed::factory()->create();

        $service = $this->mock(FeedService::class);
        $service->shouldReceive('syncFeed')->once()->andThrow(new \Exception('boom'));
        $service->shouldReceive('recordSyncFailure')->once();

        try {
            app(SyncFeed::class, ['feedId' => $feed->id])->handle($service);
        } catch (\Exception $e) {
            expect($e->getMessage())->toBe('boom');
        }
    });
});

describe('SyncFeeds', function () {
    it('dispatches a SyncFeed job for every subscribed feed', function () {
        Queue::fake();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forFeed($feed)->count(2)->create();

        app(SyncFeeds::class)->handle();

        Queue::assertPushed(SyncFeed::class, 1);
    });
});

describe('DeleteOldFeedItems', function () {
    it('deletes feed items older than the retention period', function () {
        $feed = Feed::factory()->create();
        FeedItem::factory()->forFeed($feed)->create([
            'published_at' => now()->subDays(100),
        ]);
        FeedItem::factory()->forFeed($feed)->create([
            'published_at' => now(),
        ]);

        app(DeleteOldFeedItems::class)->handle(app(FeedService::class));

        expect(FeedItem::count())->toBe(1);
    });
});
