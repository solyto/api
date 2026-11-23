<?php

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Feeds\Services\FeedReader;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Users\Models\Friend;
use App\Shared\Services\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function feedItemAt(Feed $feed, string $publishedAt, string $title = 'Post', string $description = 'Body'): FeedItem
{
    return FeedItem::factory()->forFeed($feed)->create([
        'title' => $title,
        'description' => $description,
        'published_at' => $publishedAt,
    ]);
}

/** @param  array<string, mixed>  $feedData */
function fakeFeedData(array $feedData)
{
    $reader = Mockery::mock(FeedReader::class);
    app()->instance(FeedReader::class, $reader);

    return $reader->shouldReceive('getFeedData')->once()->andReturn($feedData);
}

// What FeedReader::getFeedData() returns for the angak.fr news page, a website whose own feed is under its path.
/** @param  array<int, mixed>  $items */
function angakFeedData(array $items = ['fake-item']): array
{
    return ['url' => 'https://angak.fr/actualites/feed/', 'title' => 'Actualités', 'items' => $items];
}

describe('Feed subscriptions CRUD', function () {
    it('lists, shows, updates and deletes subscriptions', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        $subscription = FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', $feed->url);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions/'.$subscription->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $subscription->id)
            ->assertJsonPath('data.url', $feed->url);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/feeds/subscriptions/'.$subscription->id, ['title' => 'Renamed Feed'])
            ->assertStatus(200)
            ->assertJsonPath('data.url', $feed->url);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/feeds/subscriptions/'.$subscription->id)
            ->assertStatus(200);

        expect(FeedSubscription::count())->toBe(0);
    });

    it('returns the stored whitelist and blacklist', function () {
        $user = makeUser();
        $subscription = FeedSubscription::factory()->forUser($user)
            ->withWhitelist(['php', 'laravel'])->withBlacklist(['ads'])->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions/'.$subscription->id)
            ->assertStatus(200)
            ->assertJsonPath('data.whitelist', 'php,laravel')
            ->assertJsonPath('data.blacklist', 'ads');
    });

    it('stores a subscription and syncs the feed', function () {
        Queue::fake();
        $user = makeUser();

        fakeFeedData(['title' => 'Example Feed', 'items' => ['fake-item']]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', [
                'title' => 'My Feed',
                'url' => 'https://example.com/feed.xml',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.url', 'https://example.com/feed.xml');

        expect(FeedSubscription::where('user_id', $user->id)->count())->toBe(1);
        expect(Feed::where('url', 'https://example.com/feed.xml')->exists())->toBeTrue();

        Queue::assertPushed(\App\Api\Feeds\Jobs\SyncFeed::class);
    });

    it('stores the feed URL a website URL resolves to', function () {
        Queue::fake();
        fakeFeedData(angakFeedData())->with('https://angak.fr/actualites/');

        $this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', ['title' => 'Angak', 'url' => 'https://angak.fr/actualites/'])
            ->assertStatus(201)
            ->assertJsonPath('data.url', 'https://angak.fr/actualites/feed/');

        expect(Feed::sole()->url)->toBe('https://angak.fr/actualites/feed/');
    });

    it('reuses the stored feed a website URL resolves to', function () {
        Queue::fake();
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://angak.fr/actualites/feed/']);
        FeedSubscription::factory()->forUser(makeUser())->forFeed($feed)->create();
        fakeFeedData(angakFeedData());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', ['title' => 'Angak', 'url' => 'https://angak.fr/actualites/'])
            ->assertStatus(201);

        expect(Feed::count())->toBe(1);
        expect(FeedSubscription::where('user_id', $user->id)->sole()->feed_id)->toBe($feed->id);
    });

    it('rejects a website URL whose feed the user already subscribes to with 409', function () {
        Queue::fake();
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://angak.fr/actualites/feed/']);
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        fakeFeedData(angakFeedData());

        expectApiError($this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', ['title' => 'Angak', 'url' => 'https://angak.fr/actualites/']), 409);

        expect(Feed::count())->toBe(1);
        expect(FeedSubscription::where('user_id', $user->id)->count())->toBe(1);
    });

    it('rejects a feed URL on a private address with 422 and fetches nothing', function () {
        Http::fake();
        app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn () => ['10.0.0.1']));
        $user = makeUser();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/feeds/subscriptions', [
            'title' => 'Internal',
            'url' => 'https://internal.example/feed.xml',
        ]);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('url');

        Http::assertNothingSent();
        expect(Feed::count())->toBe(0);
        expect(FeedSubscription::count())->toBe(0);
    });

    it('rejects a feed URL whose host does not resolve with 422 and says so', function () {
        Http::fake();
        app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn () => []));

        $response = $this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', ['title' => 'Typo', 'url' => 'https://nowhere.example/feed.xml']);

        expectApiError($response, 422);
        $response->assertJsonPath('errors.url.0', 'The feed URL could not be resolved.');

        Http::assertNothingSent();
        expect(Feed::count())->toBe(0);
    });

    it('rejects a feed that redirects to an unparseable Location with 422', function (string $location) {
        Http::fake(['https://bad.example/*' => Http::response('', 302, ['Location' => $location])]);

        expectApiError($this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', ['title' => 'Bad', 'url' => 'https://bad.example/feed.xml']), 422);

        expect(Feed::count())->toBe(0);
    })->with(['//', 'http://', 'http://:80/x']);

    it('rejects a subscription over the limit with 422, counting only the user\'s own', function () {
        Queue::fake();
        config(['feeds.max_subscriptions' => 1]);
        $user = makeUser();
        $other = makeUser();
        FeedSubscription::factory()->forUser($user)->create();
        FeedSubscription::factory()->forUser(makeUser())->count(3)->create();
        fakeFeedData(['title' => 'Example Feed', 'items' => ['fake-item']]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', ['title' => 'One more', 'url' => 'https://example.com/feed.xml']);

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('url');
        expect(FeedSubscription::where('user_id', $user->id)->count())->toBe(1);

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', ['title' => 'First', 'url' => 'https://example.com/feed.xml'])
            ->assertStatus(201);
    });

    it('rejects subscribing to an existing feed twice', function () {
        Queue::fake();
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://example.com/feed.xml']);
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        expectApiError($this->actingAs($user, 'sanctum')->postJson('/api/v1/feeds/subscriptions', [
            'title' => 'Again',
            'url' => 'https://example.com/feed.xml',
        ]), 409);
    });
});

describe('Feed items and discovery', function () {
    it('lists feed items', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        $subscription = FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        FeedItem::factory()->forFeed($feed)->count(2)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    });

    it('lists available feeds', function () {
        $user = makeUser();
        Feed::factory()->count(3)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/available')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    });

    it('searches feeds', function () {
        $user = makeUser();
        Feed::factory()->create(['title' => 'Tech Blog']);
        Feed::factory()->create(['title' => 'Cooking Blog']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/search?search=tech')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Tech Blog');
    });

    it('lists friend feeds', function () {
        $user = makeUser();
        $friend = makeUser();
        Friend::factory()->forUsers($user, $friend)->create();

        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($friend)->forFeed($feed)->create();
        FeedItem::factory()->forFeed($feed)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/friends')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('tests a feed url with existing items', function () {
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://example.com/feed.xml']);
        FeedItem::factory()->forFeed($feed)->create(['title' => 'Latest Article']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/test', ['url' => 'https://example.com/feed.xml'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Latest Article');
    });

    it('returns cached items with an ISO-8601 published_at when testing a known feed url', function () {
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://example.com/feed.xml']);
        $item = FeedItem::factory()->forFeed($feed)->create([
            'title' => 'Latest Article',
            'link' => 'https://example.com/latest',
            'published_at' => '2026-11-08 12:00:00',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/test', ['url' => 'https://example.com/feed.xml'])
            ->assertStatus(200)
            ->assertJsonPath('data', [[
                'id' => $item->feed_item_id,
                'title' => 'Latest Article',
                'link' => 'https://example.com/latest',
                'published_at' => '2026-11-08T12:00:00.000000Z',
            ]]);
    });

    it('tests a website URL with the stored items of the feed it resolves to', function () {
        $feed = Feed::factory()->create(['url' => 'https://angak.fr/actualites/feed/']);
        FeedItem::factory()->forFeed($feed)->create(['title' => 'Stored Article']);
        $reader = $this->mock(FeedReader::class);
        $reader->shouldReceive('getFeedData')->once()->with('https://angak.fr/actualites/')->andReturn(angakFeedData([]));
        $reader->shouldReceive('getItems')->never();

        $this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/test', ['url' => 'https://angak.fr/actualites/'])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Stored Article');
    });

    it('tests a website URL with the live items of the feed it resolves to', function () {
        Http::fake([
            'https://angak.fr/actualites/' => Http::response(
                file_get_contents(base_path('tests/Fixtures/angak_actualites.html')),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://angak.fr/actualites/feed/' => Http::response(
                file_get_contents(base_path('tests/Fixtures/feed.rss')),
                200,
                ['Content-Type' => 'application/rss+xml']
            ),
            '*' => Http::response('Not found', 404),
        ]);

        $this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/test', ['url' => 'https://angak.fr/actualites/'])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'First Post');
    });

    it('answers 422 when testing a feed that redirects to an unparseable Location', function (string $location) {
        Http::fake(['https://bad.example/*' => Http::response('', 302, ['Location' => $location])]);

        expectApiError($this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/test', ['url' => 'https://bad.example/feed.xml']), 422);
    })->with(['//', 'http://', 'http://:80/x']);

    it('says when testing a feed whose host does not resolve', function () {
        Http::fake();
        app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn () => []));

        $response = $this->actingAs(makeUser(), 'sanctum')
            ->postJson('/api/v1/feeds/test', ['url' => 'https://nowhere.example/feed.xml']);

        expectApiError($response, 422);
        $response->assertJsonPath('errors.url.0', 'The feed URL could not be resolved.');

        Http::assertNothingSent();
    });
});

describe('Feed items list', function () {
    it('merges items of all subscribed feeds newest first', function () {
        $user = makeUser();
        $feedA = Feed::factory()->create();
        $feedB = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feedA)->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feedB)->create();

        $a1 = feedItemAt($feedA, '2026-11-08 10:00:00');
        $b1 = feedItemAt($feedB, '2026-11-08 12:00:00');
        $a2 = feedItemAt($feedA, '2026-11-08 11:00:00');
        $b2 = feedItemAt($feedB, '2026-11-07 09:00:00');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');

        expect(array_column($response->json('data'), 'id'))->toBe([$b1->id, $a2->id, $a1->id, $b2->id]);
    });

    it('returns the full item shape with an ISO-8601 published_at', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        $item = FeedItem::factory()->forFeed($feed)->create([
            'title' => 'Title',
            'description' => 'Desc',
            'link' => 'https://example.com/a',
            'image_url' => 'https://example.com/a.jpg',
            'published_at' => '2026-11-08 12:00:00',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonPath('data', [[
                'id' => $item->id,
                'title' => 'Title',
                'link' => 'https://example.com/a',
                'description' => 'Desc',
                'image_url' => 'https://example.com/a.jpg',
                'feed_id' => $feed->id,
                'published_at' => '2026-11-08T12:00:00.000000Z',
                'saved_link_id' => null,
            ]])
            ->assertJsonPath('meta', ['has_more' => false, 'feed_counts' => [$feed->id => 1]]);
    });

    it('sorts items without published_at last and returns them as null', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        $undated = FeedItem::factory()->forFeed($feed)->create(['published_at' => null]);
        $dated = feedItemAt($feed, '2026-11-08 12:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $dated->id)
            ->assertJsonPath('data.1.id', $undated->id)
            ->assertJsonPath('data.1.published_at', null);
    });

    it('keeps only whitelisted items, case-insensitive on title and description with trimmed keywords', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create(['whitelist' => 'PHP, laravel']);

        $inTitle = feedItemAt($feed, '2026-11-08 12:00:00', 'New php release', 'Notes');
        $inDescription = feedItemAt($feed, '2026-11-08 11:00:00', 'Release', 'All about Laravel');
        feedItemAt($feed, '2026-11-08 10:00:00', 'Gardening', 'Tomatoes');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200);

        expect(array_column($response->json('data'), 'id'))->toBe([$inTitle->id, $inDescription->id]);
    });

    it('drops blacklisted items', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create(['blacklist' => 'ads, Sponsored']);

        feedItemAt($feed, '2026-11-08 12:00:00', 'SPONSORED: buy now', 'Body');
        feedItemAt($feed, '2026-11-08 11:00:00', 'Post', 'Contains ads');
        $kept = feedItemAt($feed, '2026-11-08 10:00:00', 'Post', 'Body');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id);
    });

    it('applies whitelist and blacklist together', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create([
            'whitelist' => 'php',
            'blacklist' => 'job',
        ]);

        $kept = feedItemAt($feed, '2026-11-08 12:00:00', 'PHP 9 released', 'Body');
        feedItemAt($feed, '2026-11-08 11:00:00', 'PHP job offer', 'Body');
        feedItemAt($feed, '2026-11-08 10:00:00', 'Rust news', 'Body');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id);
    });

    it('filters per subscription and counts per feed after filtering across all pages', function () {
        $user = makeUser();
        $feedA = Feed::factory()->create();
        $feedB = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feedA)->create(['blacklist' => 'skip']);
        FeedSubscription::factory()->forUser($user)->forFeed($feedB)->create();

        feedItemAt($feedA, '2026-11-08 12:00:00');
        feedItemAt($feedA, '2026-11-08 11:00:00', 'skip me');
        feedItemAt($feedA, '2026-11-08 10:00:00');
        feedItemAt($feedB, '2026-11-08 09:00:00', 'skip is fine here');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items?limit=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.feed_counts', [$feedA->id => 2, $feedB->id => 1]);
    });

    it('slices pages by offset and limit and reports has_more', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        $items = collect(range(1, 5))->map(
            fn (int $i) => feedItemAt($feed, sprintf('2026-11-08 %02d:00:00', 10 - $i))
        );

        $first = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items?offset=0&limit=2')
            ->assertStatus(200)
            ->assertJsonPath('meta.has_more', true);
        expect(array_column($first->json('data'), 'id'))->toBe([$items[0]->id, $items[1]->id]);

        $second = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items?offset=2&limit=2')
            ->assertStatus(200)
            ->assertJsonPath('meta.has_more', true);
        expect(array_column($second->json('data'), 'id'))->toBe([$items[2]->id, $items[3]->id]);

        $last = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items?offset=4&limit=2')
            ->assertStatus(200)
            ->assertJsonPath('meta.has_more', false);
        expect(array_column($last->json('data'), 'id'))->toBe([$items[4]->id]);
    });

    it('filters to one feed with paging while feed_counts stay global', function () {
        $user = makeUser();
        $feedA = Feed::factory()->create();
        $feedB = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feedA)->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feedB)->create();

        feedItemAt($feedA, '2026-11-08 12:00:00');
        $b1 = feedItemAt($feedB, '2026-11-08 11:00:00');
        $b2 = feedItemAt($feedB, '2026-11-08 10:00:00');
        feedItemAt($feedA, '2026-11-08 09:00:00');

        $first = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/feeds/items?feed_id={$feedB->id}&limit=1")
            ->assertStatus(200)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.feed_counts', [$feedA->id => 2, $feedB->id => 2]);
        expect(array_column($first->json('data'), 'id'))->toBe([$b1->id]);

        $second = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/feeds/items?feed_id={$feedB->id}&offset=1&limit=1")
            ->assertStatus(200);
        expect(array_column($second->json('data'), 'id'))->toBe([$b2->id]);
    });

    it('rejects a non-uuid feed_id', function () {
        $response = $this->actingAs(makeUser(), 'sanctum')->getJson('/api/v1/feeds/items?feed_id=abc');

        expectApiError($response, 422);
        $response->assertJsonValidationErrors('feed_id');
    });

    it('returns every item for limit=all with has_more false', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        collect(range(1, 3))->each(
            fn (int $i) => feedItemAt($feed, sprintf('2026-11-08 %02d:00:00', $i))
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items?limit=all&offset=2')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.has_more', false);
    });

    it('returns the id of the user\'s library link with the same url as saved_link_id', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        $saved = FeedItem::factory()->forFeed($feed)->create(['link' => 'https://example.com/saved', 'published_at' => '2026-11-08 12:00:00']);
        FeedItem::factory()->forFeed($feed)->create(['link' => 'https://example.com/other', 'published_at' => '2026-11-08 11:00:00']);
        $link = LibraryLink::factory()->forUser($user)->create(['url' => 'https://example.com/saved']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $saved->id)
            ->assertJsonPath('data.0.saved_link_id', $link->id)
            ->assertJsonPath('data.1.saved_link_id', null);
    });

    it('ignores library links of other users for saved_link_id', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        FeedItem::factory()->forFeed($feed)->create(['link' => 'https://example.com/saved']);
        LibraryLink::factory()->forUser(makeUser())->create(['url' => 'https://example.com/saved']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonPath('data.0.saved_link_id', null);
    });

    it('returns the newest link as saved_link_id when the url is saved twice', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();
        FeedItem::factory()->forFeed($feed)->create(['link' => 'https://example.com/saved']);
        LibraryLink::factory()->forUser($user)->create(['url' => 'https://example.com/saved', 'created_at' => '2026-11-01 10:00:00']);
        $newest = LibraryLink::factory()->forUser($user)->create(['url' => 'https://example.com/saved', 'created_at' => '2026-11-02 10:00:00']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonPath('data.0.saved_link_id', $newest->id);
    });
});

// `feeds/friends` is shared by design and not asserted here.
describe('Feed tenant isolation', function () {
    it('hides another user\'s subscription and forbids access to it', function () {
        $user = makeUser();
        $other = makeUser();
        $feed = Feed::factory()->create();
        $subscription = FeedSubscription::factory()->forUser($other)->forFeed($feed)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        expectApiError($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions/'.$subscription->id), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/feeds/subscriptions/'.$subscription->id, ['title' => 'Hijacked']), 403);

        expectApiError($this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/feeds/subscriptions/'.$subscription->id), 403);

        expect(FeedSubscription::find($subscription->id))->not->toBeNull()
            ->and(FeedSubscription::find($subscription->id)->title)->toBe($subscription->title);
    });

    it('does not list items of feeds only another user subscribes to', function () {
        $user = makeUser();
        $other = makeUser();
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forUser($other)->forFeed($feed)->create();
        FeedItem::factory()->forFeed($feed)->count(2)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/items')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });
});
