<?php

use App\Api\Feeds\Jobs\DeleteOldFeedItems;
use App\Api\Feeds\Jobs\ImportFeedSubscriptions;
use App\Api\Feeds\Jobs\SyncFeed;
use App\Api\Feeds\Jobs\SyncFeeds;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Feeds\Services\FeedReader;
use App\Api\Feeds\Services\FeedService;
use App\Api\Feeds\Services\OpmlImportService;
use App\Api\Users\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SimplePie\Misc;

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

/**
 * @param  array<int, string>  $urls
 */
function runningFeedImport(User $user, array $urls): void
{
    $outlines = implode('', array_map(fn (string $url) => '<outline text="'.$url.'" xmlUrl="'.$url.'"/>', $urls));
    $service = app(OpmlImportService::class);

    $service->start($user, '<?xml version="1.0"?><opml version="2.0"><body>'.$outlines.'</body></opml>');
    $service->select($user, $urls);
}

/**
 * @return array<string, string|null>
 */
function feedImportStatuses(User $user): array
{
    return array_map(
        fn (?\App\Api\Feeds\Enums\FeedImportStatus $status) => $status?->value,
        array_column(app(OpmlImportService::class)->state($user)->feeds, 'status', 'url')
    );
}

describe('ImportFeedSubscriptions', function () {
    it('gives every selected feed its outcome and finishes', function () {
        Queue::fake();
        $user = makeUser();
        $known = Feed::factory()->create(['url' => 'https://known.example/feed']);
        $race = Feed::factory()->create(['url' => 'https://race.example/feed']);
        Http::fake([
            'https://ok.example/*' => Http::response(file_get_contents(__DIR__.'/../../../../tests/Fixtures/feed.rss'), 200),
            'https://down.example/*' => Http::failedConnection(),
            'https://slow.example/*' => Http::failedConnection('cURL error 28: Operation timed out'),
            'https://missing.example/*' => Http::response('Not found', 404),
            'https://broken.example/*' => Http::response('Server error', 500),
            'https://html.example/*' => Http::response('<!DOCTYPE html><html><body><p>Hello</p></body></html>', 200),
        ]);
        runningFeedImport($user, [
            'https://known.example/feed',
            'https://race.example/feed',
            'https://ok.example/feed',
            'https://down.example/feed',
            'https://slow.example/feed',
            'https://missing.example/feed',
            'https://broken.example/feed',
            'https://html.example/feed',
        ]);
        FeedSubscription::factory()->forUser($user)->forFeed($race)->create();

        app(ImportFeedSubscriptions::class, ['userId' => $user->id])->handle(app(OpmlImportService::class));

        expect(feedImportStatuses($user))->toBe([
            'https://known.example/feed' => 'subscribed',
            'https://race.example/feed' => 'already_subscribed',
            'https://ok.example/feed' => 'subscribed',
            'https://down.example/feed' => 'unreachable',
            'https://slow.example/feed' => 'unreachable',
            'https://missing.example/feed' => 'unreachable',
            'https://broken.example/feed' => 'unreachable',
            'https://html.example/feed' => 'not_a_feed',
        ]);

        $state = app(OpmlImportService::class)->state($user);
        expect($state->stage->value)->toBe('finished');
        expect($state->processed())->toBe(8);

        $created = Feed::where('url', 'https://ok.example/feed')->first();
        expect($created->title)->toBe('Fixture Feed');
        expect(FeedSubscription::where('user_id', $user->id)->pluck('feed_id')->sort()->values()->all())
            ->toBe(collect([$known->id, $race->id, $created->id])->sort()->values()->all());
        expect(Feed::where('url', 'https://html.example/feed')->exists())->toBeFalse();

        Queue::assertPushed(SyncFeed::class, 2);
        Queue::assertPushed(SyncFeed::class, fn (SyncFeed $job) => in_array(
            (fn () => $this->feedId)->call($job),
            [$known->id, $created->id],
            true
        ));
    });

    it('subscribes an existing feed without fetching it', function () {
        Queue::fake();
        Http::fake();
        $user = makeUser();
        Feed::factory()->create(['url' => 'https://known.example/feed']);
        runningFeedImport($user, ['https://known.example/feed']);

        app(ImportFeedSubscriptions::class, ['userId' => $user->id])->handle(app(OpmlImportService::class));

        Http::assertNothingSent();
        expect(feedImportStatuses($user))->toBe(['https://known.example/feed' => 'subscribed']);
    });

    it('fetches with the SimplePie user agent and a 5 second timeout', function () {
        Queue::fake();
        Http::fake(['*' => Http::response(file_get_contents(__DIR__.'/../../../../tests/Fixtures/feed.rss'), 200)]);
        $user = makeUser();
        runningFeedImport($user, ['https://ok.example/feed']);

        app(ImportFeedSubscriptions::class, ['userId' => $user->id])->handle(app(OpmlImportService::class));

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://ok.example/feed'
            && $request->hasHeader('User-Agent', Misc::get_default_useragent()));
    });

    it('fetches new feeds in chunks of 25 and writes progress after each', function () {
        Queue::fake();
        $user = makeUser();
        $urls = array_map(fn (int $i) => 'https://feed'.$i.'.example/rss', range(1, 30));
        runningFeedImport($user, $urls);
        $seen = [];
        Http::fake(function (HttpRequest $request) use ($user, &$seen) {
            $seen[] = app(OpmlImportService::class)->state($user)->processed();

            return Http::response('', 404);
        });

        app(ImportFeedSubscriptions::class, ['userId' => $user->id])->handle(app(OpmlImportService::class));

        expect(array_count_values($seen))->toBe([0 => 25, 25 => 5]);
        expect(app(OpmlImportService::class)->state($user)->processed())->toBe(30);
    });

    it('does nothing when the import is not running', function () {
        Http::fake();
        $user = makeUser();
        app(OpmlImportService::class)->start($user, '<opml><body><outline xmlUrl="https://ok.example/feed"/></body></opml>');

        app(ImportFeedSubscriptions::class, ['userId' => $user->id])->handle(app(OpmlImportService::class));

        Http::assertNothingSent();
        expect(app(OpmlImportService::class)->state($user)->stage->value)->toBe('parsed');
    });

    it('finishes the import when the job fails part way through', function () {
        Queue::fake();
        $user = makeUser();
        Feed::factory()->create(['url' => 'https://known.example/feed']);
        Http::fake(['*' => Http::response('<rss/>', 200)]);
        $this->mock(FeedReader::class)->shouldReceive('parseFeedData')->andThrow(new RuntimeException('boom'));
        runningFeedImport($user, ['https://known.example/feed', 'https://new.example/feed']);
        $job = app(ImportFeedSubscriptions::class, ['userId' => $user->id]);

        expect(fn () => $job->handle(app(OpmlImportService::class)))->toThrow(RuntimeException::class);
        expect(app(OpmlImportService::class)->state($user)->stage->value)->toBe('running');

        $job->failed(new RuntimeException('boom'));

        expect(app(OpmlImportService::class)->state($user)->stage->value)->toBe('finished');
        expect(feedImportStatuses($user))->toBe([
            'https://known.example/feed' => 'subscribed',
            'https://new.example/feed' => 'unreachable',
        ]);
    });
});
