<?php

use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;

function feedItemAt(Feed $feed, string $publishedAt, string $title = 'Post', string $description = 'Body'): FeedItem
{
    return FeedItem::factory()->forFeed($feed)->create([
        'title' => $title,
        'description' => $description,
        'published_at' => $publishedAt,
    ]);
}

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
});
