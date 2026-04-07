<?php

namespace App\Api\Feeds\Services;

use App\Api\Feeds\Exceptions\FeedException;
use App\Api\Feeds\Jobs\SyncFeed;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Users\Models\Friend;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FeedService
{
    private const string CACHE_KEY_USER_FEEDS = 'user_feeds';
    private const string CACHE_KEY_FEED_ITEMS = 'feed_items';
    private const int CACHE_TTL_USER_FEEDS = 86400;
    private const int CACHE_TTL_FEED_ITEMS = 3600;

    public function __construct(
        private readonly FeedReader $feedReader,
        private readonly UserCacheService $cache,
    ) {}

    public function addFeed(string $title, string $url): bool
    {
        $feed = Feed::where('url', $url)->first();

        if ($feed) {
            return true;
        }

        $feed = Feed::create([
            'title' => $title,
            'url' => $url,
        ]);

        return $feed !== null;
    }

    public function getTestFeedItems(string $url): array
    {
        $feed = Feed::where('url', $url)->first();

        if ($feed) {
            $items = $this->getFeedItems($feed->id);

            if ($items->isNotEmpty()) {
                return $items->map(fn($item) => [
                    'id'           => $item->feed_item_id,
                    'title'        => $item->title,
                    'link'         => $item->link,
                    'published_at' => $item->published_at,
                ])
                ->values()
                ->toArray();
            }
        }

        $rssItems = $this->getFeedItemsFromRss($url);

        return array_map(fn($item) => [
            'id'           => $item->get_id(),
            'title'        => $item->get_title(),
            'link'         => $item->get_link(),
            'published_at' => Carbon::parse($item->get_date()),
        ], $rssItems);
    }

    /**
     * @throws FeedException
     */
    public function createSubscription(string $userId, string $title, string $url, ?string $whitelist, ?string $blacklist): FeedSubscription
    {
        $feed = Feed::where('url', $url)->first();

        if ($feed && FeedSubscription::where('user_id', $userId)->where('feed_id', $feed->id)->exists()) {
            throw new \App\Api\Feeds\Exceptions\FeedAlreadySubscribedException('Already subscribed to this feed');
        }

        if (!$feed) {
            $feedData = $this->feedReader->getFeedData($url);

            if (!$feedData || empty($feedData['items'])) {
                throw new FeedException('Failed to fetch feed items from RSS');
            }

            $feed = Feed::create([
                'title' => $feedData['title'] ?? $title,
                'url' => $url,
                'created_by' => $userId,
            ]);
        }

        $subscription = FeedSubscription::create([
            'title' => $title,
            'whitelist' => $whitelist,
            'blacklist' => $blacklist,
            'feed_id' => $feed->id,
            'user_id' => $userId,
        ]);

        $this->cache->forget([self::CACHE_KEY_USER_FEEDS, $userId]);

        SyncFeed::dispatch($feed->id);

        return $subscription;
    }

    public function getUserFeeds(string $userId): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_USER_FEEDS, $userId],
            self::CACHE_TTL_USER_FEEDS,
            fn() => FeedSubscription::forUser($userId)->with(['feed'])->get()
        );
    }

    public function filterFeedItems(Collection $items, FeedSubscription $subscription): Collection
    {
        return $items->filter(function ($item) use ($subscription) {
            $text = strtolower($item->title . ' ' . $item->description);

            if ($subscription->whitelist) {
                $keywords = array_map('strtolower', explode(',', $subscription->whitelist));
                if (!collect($keywords)->contains(fn($keyword) => str_contains($text, trim($keyword)))) {
                    return false;
                }
            }

            if ($subscription->blacklist) {
                $keywords = array_map('strtolower', explode(',', $subscription->blacklist));
                if (collect($keywords)->contains(fn($keyword) => str_contains($text, trim($keyword)))) {
                    return false;
                }
            }

            return true;
        });
    }

    public function getFeedItems(string $feedId): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_FEED_ITEMS, $feedId],
            self::CACHE_TTL_FEED_ITEMS,
            fn() => FeedItem::where('feed_id', $feedId)
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function getFeedItemsFromRss(string $url): array|false
    {
        return $this->feedReader->getItems($url);
    }

    public function getFriendFeeds(string $userId): Collection
    {
        $friendIds = Friend::where('user_id_1', $userId)
            ->orWhere('user_id_2', $userId)
            ->get()
            ->map(fn($f) => $f->user_id_1 === $userId ? $f->user_id_2 : $f->user_id_1);

        if ($friendIds->isEmpty()) {
            return collect();
        }

        $userFeedIds = FeedSubscription::where('user_id', $userId)->pluck('feed_id');

        return FeedSubscription::whereIn('user_id', $friendIds)
            ->whereNotIn('feed_id', $userFeedIds)
            ->with(['feed' => fn($q) => $q->withCount('subscriptions'), 'user:id,name'])
            ->get()
            ->groupBy('feed_id')
            ->map(function ($subscriptions) {
                $feed = $subscriptions->first()->feed;
                $friendNames = $subscriptions->map(fn($s) => $s->user->name)->unique()->values()->toArray();

                return [
                    'id' => $feed->id,
                    'title' => $feed->title,
                    'url' => $feed->url,
                    'subscriber_count' => $feed->subscriptions_count,
                    'friend_names' => $friendNames,
                ];
            })
            ->values();
    }

    public function getAvailableFeeds(int $offset = 0): Collection
    {
        return Feed::withCount('subscriptions')
            ->orderBy('title')
            ->offset($offset)
            ->limit(50)
            ->get();
    }

    public function searchFeeds(string $query): Collection
    {
        return Feed::withCount('subscriptions')
            ->where('title', 'like', '%' . $query . '%')
            ->orWhere('url', 'like', '%' . $query . '%')
            ->limit(20)
            ->get();
    }

    public function updateSubscription(FeedSubscription $subscription, array $data): FeedSubscription
    {
        $subscription->update($data);

        $this->cache->forget([self::CACHE_KEY_USER_FEEDS, $subscription->user_id]);

        return $subscription;
    }

    public function deleteSubscription(FeedSubscription $subscription): void
    {
        $userId = $subscription->user_id;
        $subscription->delete();

        $this->cache->forget([self::CACHE_KEY_USER_FEEDS, $userId]);
    }

    public function syncFeed(string $feedId): bool
    {
        $feed = Feed::find($feedId);

        if (!$feed) {
            return false;
        }

        $items = $this->getFeedItemsFromRss($feed->url);

        if (!$items) {
            return false;
        }

        foreach ($items as $item) {
            $feedItem = FeedItem::firstOrCreate(
                ['feed_item_id' => $item->get_id()],
                [
                    'title'        => $item->get_title(),
                    'description'  => $item->get_description(),
                    'link'         => $item->get_link(),
                    'image_url'    => $this->feedReader->extractImageUrl($item),
                    'published_at' => Carbon::parse($item->get_date()),
                    'feed_id'      => $feedId,
                ]
            );

            if (!$feedItem->wasRecentlyCreated && $feedItem->image_url === null) {
                $imageUrl = $this->feedReader->extractImageUrl($item);
                if ($imageUrl) {
                    $feedItem->update(['image_url' => $imageUrl]);
                }
            }
        }

        $this->cache->forget([self::CACHE_KEY_FEED_ITEMS, $feedId]);

        return true;
    }
}
