<?php

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Feed Factory', function () {
    it('creates a valid feed', function () {
        $user = User::factory()->create();
        $feed = Feed::factory()->create([
            'created_by' => $user->id,
        ]);

        expect($feed->title)->toBeString();
        expect($feed->url)->toBeString();
    });

    it('creates a feed with custom title', function () {
        $feed = Feed::factory()->withTitle('Tech Blog')->create();

        expect($feed->title)->toBe('Tech Blog');
    });

    it('creates a feed with custom url', function () {
        $url = 'https://example.com/feed.xml';
        $feed = Feed::factory()->withUrl($url)->create();

        expect($feed->url)->toBe($url);
    });
});

describe('FeedItem Factory', function () {
    it('creates a valid feed item', function () {
        $feed = Feed::factory()->create();
        $item = FeedItem::factory()->forFeed($feed)->create();

        expect($item->title)->toBeString();
        expect($item->link)->toBeString();
        expect($item->feed_id)->toBe($feed->id);
        expect($item->published_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('creates an item with custom title', function () {
        $item = FeedItem::factory()->withTitle('New Article')->create();

        expect($item->title)->toBe('New Article');
    });

    it('creates a recent item', function () {
        $item = FeedItem::factory()->recent()->create();

        expect($item->published_at)->greaterThan(now()->subDays(7));
    });

    it('creates a todays item', function () {
        $item = FeedItem::factory()->today()->create();

        expect($item->published_at)->toBeGreaterThanOrEqual(today()->startOfDay());
    });

    it('creates an item for feed', function () {
        $feed = Feed::factory()->create();
        $item = FeedItem::factory()->forFeed($feed)->create();

        expect($item->feed_id)->toBe($feed->id);
    });
});

describe('FeedSubscription Factory', function () {
    it('creates a valid subscription', function () {
        $user = User::factory()->create();
        $feed = Feed::factory()->create();
        $subscription = FeedSubscription::factory()->forUserAndFeed($user, $feed)->create();

        expect($subscription->title)->toBeString();
        expect($subscription->user_id)->toBe($user->id);
        expect($subscription->feed_id)->toBe($feed->id);
    });

    it('creates a subscription with custom title', function () {
        $subscription = FeedSubscription::factory()->withTitle('My Feed')->create();

        expect($subscription->title)->toBe('My Feed');
    });

    it('creates a subscription with whitelist', function () {
        $keywords = ['important', 'work'];
        $subscription = FeedSubscription::factory()->withWhitelist($keywords)->create();

        expect($subscription->whitelist)->toBeArray();
        expect($subscription->whitelist)->toContain('important');
        expect($subscription->whitelist)->toContain('work');
    });

    it('creates a subscription with blacklist', function () {
        $keywords = ['spam', 'ads'];
        $subscription = FeedSubscription::factory()->withBlacklist($keywords)->create();

        expect($subscription->blacklist)->toBeArray();
        expect($subscription->blacklist)->toContain('spam');
        expect($subscription->blacklist)->toContain('ads');
    });

    it('creates a subscription for user', function () {
        $user = User::factory()->create();
        $feed = Feed::factory()->create();
        $subscription = FeedSubscription::factory()->forUser($user)->forFeed($feed)->create();

        expect($subscription->user_id)->toBe($user->id);
    });
});

describe('Feed Model', function () {
    it('has correct fillable attributes', function () {
        $feed = new Feed;

        expect($feed->getFillable())->toContain('title');
        expect($feed->getFillable())->toContain('url');
        expect($feed->getFillable())->toContain('created_by');
    });

    it('has subscriptions relationship', function () {
        $feed = Feed::factory()->create();
        FeedSubscription::factory()->forFeed($feed)->create(3);

        expect($feed->subscriptions)->toHaveCount(3);
    });

    it('has items relationship', function () {
        $feed = Feed::factory()->create();
        FeedItem::factory()->forFeed($feed)->create(5);

        expect($feed->items)->toHaveCount(5);
    });
});

describe('FeedItem Model', function () {
    it('has correct fillable attributes', function () {
        $item = new FeedItem;

        expect($item->getFillable())->toContain('title');
        expect($item->getFillable())->toContain('link');
        expect($item->getFillable())->toContain('description');
        expect($item->getFillable())->toContain('published_at');
        expect($item->getFillable())->toContain('feed_id');
        expect($item->getFillable())->toContain('feed_item_id');
    });

    it('casts published_at correctly', function () {
        $item = FeedItem::factory()->create([
            'published_at' => now(),
        ]);

        expect($item->published_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('belongs to feed', function () {
        $feed = Feed::factory()->create();
        $item = FeedItem::factory()->forFeed($feed)->create();

        expect($item->feed->id)->toBe($feed->id);
    });
});

describe('FeedSubscription Model', function () {
    it('has correct fillable attributes', function () {
        $subscription = new FeedSubscription;

        expect($subscription->getFillable())->toContain('title');
        expect($subscription->getFillable())->toContain('whitelist');
        expect($subscription->getFillable())->toContain('blacklist');
        expect($subscription->getFillable())->toContain('user_id');
        expect($subscription->getFillable())->toContain('feed_id');
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $subscription = FeedSubscription::factory()->forUser($user)->create();

        expect($subscription->user->id)->toBe($user->id);
    });

    it('belongs to feed', function () {
        $feed = Feed::factory()->create();
        $subscription = FeedSubscription::factory()->forUserAndFeed($user, $feed)->create();

        expect($subscription->feed->id)->toBe($feed->id);
    });

    it('scopes by user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $feed = Feed::factory()->create();

        FeedSubscription::factory()->forUser($user1)->create();
        FeedSubscription::factory()->forUser($user2)->create();

        $user1Subs = FeedSubscription::where('user_id', $user1->id)->get();
        $user2Subs = FeedSubscription::where('user_id', $user2->id)->get();

        expect($user1Subs)->toHaveCount(1);
        expect($user2Subs)->toHaveCount(1);
    });
});
