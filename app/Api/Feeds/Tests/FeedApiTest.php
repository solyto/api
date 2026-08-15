<?php

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Feeds\Services\FeedReader;
use App\Api\Users\Models\Friend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

describe('Feed subscriptions CRUD', function () {
    it('lists, shows, updates and deletes subscriptions', function () {
        $user = makeUser();
        $feed = Feed::factory()->create();
        $subscription = FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/feeds/subscriptions/'.$subscription->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $subscription->id);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/feeds/subscriptions/'.$subscription->id, ['title' => 'Renamed Feed'])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/feeds/subscriptions/'.$subscription->id)
            ->assertStatus(200);

        expect(FeedSubscription::count())->toBe(0);
    });

    it('stores a subscription and syncs the feed', function () {
        Queue::fake();
        $user = makeUser();

        $reader = $this->mock(FeedReader::class);
        $reader->shouldReceive('getFeedData')->once()->andReturn([
            'title' => 'Example Feed',
            'items' => ['fake-item'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', [
                'title' => 'My Feed',
                'url' => 'https://example.com/feed.xml',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        expect(FeedSubscription::where('user_id', $user->id)->count())->toBe(1);
        expect(Feed::where('url', 'https://example.com/feed.xml')->exists())->toBeTrue();

        Queue::assertPushed(\App\Api\Feeds\Jobs\SyncFeed::class);
    });

    it('rejects subscribing to an existing feed twice', function () {
        Queue::fake();
        $user = makeUser();
        $feed = Feed::factory()->create(['url' => 'https://example.com/feed.xml']);
        FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/feeds/subscriptions', [
                'title' => 'Again',
                'url' => 'https://example.com/feed.xml',
            ])
            ->assertStatus(409);
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
});
