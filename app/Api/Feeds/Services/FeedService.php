<?php

namespace App\Api\Feeds\Services;

use App\Shared\Pulse\PulseMetrics;
use App\Api\Feeds\Exceptions\FeedAlreadySubscribedException;
use App\Api\Feeds\Exceptions\FeedException;
use App\Api\Feeds\Exceptions\FeedSubscriptionLimitException;
use App\Api\Feeds\Jobs\SyncFeed;
use App\Api\Feeds\Models\Feed;
use App\Api\Feeds\Models\FeedItem;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Users\Models\Friend;
use App\Api\Users\Models\User;
use App\Shared\Exceptions\BlockedUrlException;
use App\Shared\Exceptions\UnresolvableHostException;
use App\Shared\Services\UserCacheService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeedService
{
    private const string CACHE_KEY_USER_FEEDS = 'user_feeds';
    // v2 since entries hold raw rows instead of models: an old entry must not be read as rows.
    private const string CACHE_KEY_FEED_ITEMS = 'feed_items_v2';
    private const int CACHE_TTL_USER_FEEDS = 86400;
    private const int CACHE_TTL_FEED_ITEMS = 86400;
    private const int MAX_ERROR_LENGTH = 500;
    private const int MAX_DESCRIPTION_LENGTH = 1000;
    private const int MAX_ITEM_ID_LENGTH = 255;
    private const int MAX_TITLE_LENGTH = 255;
    private const int MAX_TITLE_DECODE_PASSES = 3;
    private const int SAVED_LINK_LOOKUP_CHUNK = 500;
    private const string UNRESOLVABLE_URL_MESSAGE = 'The feed URL could not be resolved.';
    private const string BLOCKED_URL_MESSAGE = 'The feed URL is not allowed.';

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

        Feed::create([
            'title' => $title,
            'url' => $url,
        ]);

        return true;
    }

    // $url may be a website: when the feed it resolves to is already stored, the stored items are returned instead of
    // the live ones.
    /** @throws ValidationException when the feed host does not resolve */
    public function getTestFeedItems(string $url): array
    {
        $storedItems = $this->storedTestFeedItems($url);

        if ($storedItems !== null) {
            return $storedItems;
        }

        try {
            $feedData = $this->feedReader->getFeedData($url);
        } catch (UnresolvableHostException) {
            throw ValidationException::withMessages(['url' => self::UNRESOLVABLE_URL_MESSAGE]);
        } catch (BlockedUrlException) {
            return [];
        }

        if (!$feedData) {
            return [];
        }

        if ($feedData['url'] !== $url) {
            $storedItems = $this->storedTestFeedItems($feedData['url']);

            if ($storedItems !== null) {
                return $storedItems;
            }
        }

        return array_map(fn($item) => [
            'id'           => $item->get_id(),
            'title'        => $item->get_title(),
            'link'         => $item->get_link(),
            'published_at' => Carbon::parse($item->get_date()),
        ], $feedData['items']);
    }

    // null when no Feed row has $url or it has no items yet.
    /** @return array<int, array<string, mixed>>|null */
    private function storedTestFeedItems(string $url): ?array
    {
        $feed = Feed::where('url', $url)->first();

        if (!$feed) {
            return null;
        }

        $items = $this->getFeedItems($feed->id);

        if ($items->isEmpty()) {
            return null;
        }

        return $this->hydrateFeedItems($items)->map(fn($item) => [
            'id'           => $item->feed_item_id,
            'title'        => $item->title,
            'link'         => $item->link,
            'published_at' => $item->published_at,
        ])
        ->values()
        ->toArray();
    }

    /**
     * @throws FeedException
     * @throws ValidationException
     */
    public function createSubscription(User $user, string $title, string $url, ?string $whitelist, ?string $blacklist): FeedSubscription
    {
        try {
            return $this->subscribe($user->id, $title, $url, fn () => $this->feedReader->getFeedData($url), $whitelist, $blacklist);
        } catch (UnresolvableHostException) {
            throw ValidationException::withMessages(['url' => self::UNRESOLVABLE_URL_MESSAGE]);
        } catch (BlockedUrlException) {
            throw ValidationException::withMessages(['url' => self::BLOCKED_URL_MESSAGE]);
        } catch (FeedSubscriptionLimitException) {
            throw ValidationException::withMessages([
                'url' => sprintf('You can subscribe to at most %d feeds.', $this->maxSubscriptions()),
            ]);
        }
    }

    public function hasReachedSubscriptionLimit(string $userId): bool
    {
        return FeedSubscription::forUser($userId)->count() >= $this->maxSubscriptions();
    }

    private function maxSubscriptions(): int
    {
        return (int) config('feeds.max_subscriptions');
    }

    // $feedData is what FeedReader::parseFeedData() returned for $url, or null when the Feed row already exists and
    // nothing was fetched.
    /** @throws FeedException */
    public function createImportedSubscription(User $user, string $title, string $url, array|false|null $feedData): FeedSubscription
    {
        return $this->subscribe($user->id, $title, $url, fn () => $feedData ?? false, null, null);
    }

    // $fetchFeedData is only called when no Feed row has $url, and returns FeedReader's feed data or false when $url
    // is not a feed.
    /**
     * @throws FeedException
     * @throws FeedSubscriptionLimitException
     * @throws BlockedUrlException from $fetchFeedData
     */
    private function subscribe(string $userId, string $title, string $url, \Closure $fetchFeedData, ?string $whitelist, ?string $blacklist): FeedSubscription
    {
        $feed = Feed::where('url', $url)->first();

        if ($feed) {
            $this->ensureNotSubscribed($userId, $feed);
        }

        if ($this->hasReachedSubscriptionLimit($userId)) {
            throw new FeedSubscriptionLimitException('Feed subscription limit reached');
        }

        if (!$feed) {
            $feed = $this->feedForFetchedData($userId, $title, $url, $fetchFeedData());
        }

        $subscription = FeedSubscription::create([
            'title' => $title,
            'whitelist' => $whitelist,
            'blacklist' => $blacklist,
            'feed_id' => $feed->id,
            'user_id' => $userId,
        ]);
        $subscription->load('feed');

        $this->cache->forget([self::CACHE_KEY_USER_FEEDS, $userId]);

        SyncFeed::dispatch($feed->id);

        return $subscription;
    }

    // $url may be a website; the Feed row is stored under the feed URL it resolved to ($feedData's 'url'), and an
    // existing row for that URL is reused. Fetched data without 'url' (OPML import) keeps $url.
    /**
     * @throws FeedException
     * @throws FeedAlreadySubscribedException
     */
    private function feedForFetchedData(string $userId, string $title, string $url, array|false $feedData): Feed
    {
        if (!$feedData || empty($feedData['items'])) {
            throw new FeedException('Failed to fetch feed items from RSS');
        }

        $feedUrl = $feedData['url'] ?? $url;

        if ($feedUrl !== $url) {
            $storedFeed = Feed::where('url', $feedUrl)->first();

            if ($storedFeed) {
                $this->ensureNotSubscribed($userId, $storedFeed);

                return $storedFeed;
            }
        }

        $feedTitle = $this->plainTitle($feedData['title']) ?: $title;

        return Feed::create([
            'title' => mb_substr($feedTitle, 0, self::MAX_TITLE_LENGTH),
            'url' => $feedUrl,
            'created_by' => $userId,
        ]);
    }

    /**
     * @throws FeedAlreadySubscribedException
     */
    private function ensureNotSubscribed(string $userId, Feed $feed): void
    {
        if (FeedSubscription::forUser($userId)->where('feed_id', $feed->id)->exists()) {
            throw new FeedAlreadySubscribedException('Already subscribed to this feed');
        }
    }

    public function getAggregatedItems(string $userId, int $offset = 0, ?int $limit = 50, ?string $feedId = null): array
    {
        $subscriptions = $this->getUserFeeds($userId);

        $items = collect();

        $cached = $this->cache->many(
            $subscriptions->map(fn (FeedSubscription $s) => [self::CACHE_KEY_FEED_ITEMS, $s->feed_id])->all()
        );

        foreach ($subscriptions as $index => $subscription) {
            $feedItems = $cached[$index] ?? $this->getFeedItems($subscription->feed_id);

            $items = $items->merge(
                $this->filterFeedItems($feedItems, $subscription)
            );
        }

        $counts = $items->countBy('feed_id')->all();
        $sorted = $items->sortByDesc('published_at');

        if ($feedId !== null) {
            $sorted = $sorted->where('feed_id', $feedId);
        }

        $page = $limit === null
            ? $sorted
            : $sorted->slice($offset, $limit);

        $items = $this->hydrateFeedItems($page);
        // After the cache read: cached feed items are shared between users, saved links are per user.
        $this->attachSavedLinkIds($userId, $items);

        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * @param  EloquentCollection<int, FeedItem>  $items
     */
    private function attachSavedLinkIds(string $userId, EloquentCollection $items): void
    {
        $urls = $items->pluck('link')->filter()->unique()->values();
        $linkIds = [];

        foreach ($urls->chunk(self::SAVED_LINK_LOOKUP_CHUNK) as $chunk) {
            // Ascending, so the newest of several links with the same URL wins the key.
            $chunkLinkIds = LibraryLink::forUser($userId)
                ->whereIn('url', $chunk->all())
                ->orderBy('created_at')
                ->pluck('id', 'url')
                ->all();

            $linkIds = [...$linkIds, ...$chunkLinkIds];
        }

        $items->each(fn (FeedItem $item) => $item->setAttribute('saved_link_id', $linkIds[$item->link] ?? null));
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return EloquentCollection<int, FeedItem>
     */
    private function hydrateFeedItems(Collection $rows): EloquentCollection
    {
        return FeedItem::hydrate($rows->map(fn (\stdClass $row) => (array) $row)->values()->all());
    }

    public function getUserFeeds(string $userId): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_USER_FEEDS, $userId],
            self::CACHE_TTL_USER_FEEDS,
            fn() => FeedSubscription::forUser($userId)->with(['feed'])->get()
        );
    }

    /**
     * @param  Collection<int, \stdClass>  $items
     * @return Collection<int, \stdClass>
     */
    public function filterFeedItems(Collection $items, FeedSubscription $subscription): Collection
    {
        $whitelist = $this->parseKeywords($subscription->whitelist);
        $blacklist = $this->parseKeywords($subscription->blacklist);

        if ($whitelist === null && $blacklist === null) {
            return $items;
        }

        return $items->filter(function ($item) use ($whitelist, $blacklist) {
            $text = strtolower($item->title . ' ' . $item->description);

            if ($whitelist !== null && !$this->containsAny($text, $whitelist)) {
                return false;
            }

            if ($blacklist !== null && $this->containsAny($text, $blacklist)) {
                return false;
            }

            return true;
        });
    }

    /**
     * @return list<string>|null
     */
    private function parseKeywords(?string $list): ?array
    {
        if (!$list) {
            return null;
        }

        return array_map(fn (string $keyword) => trim(strtolower($keyword)), explode(',', $list));
    }

    /**
     * @param  list<string>  $keywords
     */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    // Raw rows, not models: serialized models are ~3x the size and every page unserializes all of them, and sorting
    // on the raw published_at string avoids building a Carbon per item. Callers hydrate what they return.
    /** @return Collection<int, \stdClass> */
    public function getFeedItems(string $feedId): Collection
    {
        return $this->cache->remember(
            [self::CACHE_KEY_FEED_ITEMS, $feedId],
            self::CACHE_TTL_FEED_ITEMS,
            fn() => FeedItem::where('feed_id', $feedId)
                ->orderBy('published_at', 'desc')
                ->toBase()
                ->get()
        );
    }

    /**
     * @throws BlockedUrlException
     */
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

        $userFeedIds = FeedSubscription::forUser($userId)->pluck('feed_id');

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

    public function findSubscription(FeedSubscription $subscription): FeedSubscription
    {
        $subscription->load('feed');

        return $subscription;
    }

    public function updateSubscription(FeedSubscription $subscription, array $data): FeedSubscription
    {
        $subscription->update($data);
        $subscription->load('feed');

        $this->cache->forget([self::CACHE_KEY_USER_FEEDS, $subscription->user_id]);

        return $subscription;
    }

    public function deleteSubscription(FeedSubscription $subscription): void
    {
        $userId = $subscription->user_id;
        $subscription->delete();

        $this->cache->forget([self::CACHE_KEY_USER_FEEDS, $userId]);
    }

    // Some feeds use very long GUIDs (Google News: 600+ chars), which don't fit the feed_item_id column and fail the
    // whole sync. Those are stored as a hash instead, so the same GUID always maps to the same row. Shorter ids stay
    // as they are, so existing rows keep matching.
    private function storedItemId(?string $id): ?string
    {
        if ($id === null || strlen($id) <= self::MAX_ITEM_ID_LENGTH) {
            return $id;
        }

        return 'sha1:'.sha1($id);
    }

    private function sanitizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Strip zero-width and other invisible Unicode characters that RSS feeds sometimes append
        return trim(preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}\x{2060}]/u', '', $value));
    }

    // SimplePie hands item and feed titles out as HTML ("LTE &amp; Solar"), but clients render them as plain text.
    // Many feeds also encode titles twice, which leaves "&amp;amp;" after SimplePie, so decoding repeats while it
    // still changes something, capped so a pathological title can't loop for long.
    // Public because OpmlImportService cleans OPML titles the same way.
    public function plainTitle(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $title = strip_tags($value);

        for ($pass = 0; $pass < self::MAX_TITLE_DECODE_PASSES; $pass++) {
            $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $title) {
                break;
            }

            $title = $decoded;
        }

        return $this->sanitizeText($title);
    }

    // Some feeds (e.g. F-Droid's "This Week in F-Droid") ship the entire newsletter as the item description. That
    // overflows the feed_items TEXT column and fails the whole sync, and the client only ever renders a short
    // preview, so descriptions are cut to a fixed length at ingest. Items without a summary get "" so the API always
    // returns a string.
    private function truncateDescription(?string $value): string
    {
        return $value === null ? '' : Str::limit($value, self::MAX_DESCRIPTION_LENGTH);
    }

    // Resolving an image can cost a full external page fetch per item, so it only happens for items being stored for
    // the first time. Retrying items that are already stored but still have no image is opt-in via $backfillImages,
    // since feeds whose items never carry an image would otherwise refetch every sync.
    public function syncFeed(string $feedId, bool $backfillImages = false): bool
    {
        $feed = Feed::find($feedId);

        if (!$feed) {
            return false;
        }

        // Measured per feed for the Pulse feed sync card; a sync that returns false or throws counts as failed.
        $startedAt = PulseMetrics::startTimer();
        [$ogLookupsBefore, $ogLookupMsBefore] = PulseMetrics::ogImageLookupStats();
        $synced = false;

        try {
            $synced = $this->syncFoundFeed($feed, $feedId, $backfillImages);

            return $synced;
        } finally {
            [$ogLookups, $ogLookupMs] = PulseMetrics::ogImageLookupStats();
            PulseMetrics::feedSync(
                $feed->url,
                PulseMetrics::elapsedMs($startedAt),
                $ogLookups - $ogLookupsBefore,
                $ogLookupMs - $ogLookupMsBefore,
                !$synced,
            );
        }
    }

    private function syncFoundFeed(Feed $feed, string $feedId, bool $backfillImages): bool
    {
        try {
            $items = $this->getFeedItemsFromRss($feed->url);
        } catch (UnresolvableHostException) {
            $this->recordSyncFailure($feedId, self::UNRESOLVABLE_URL_MESSAGE);

            return false;
        } catch (BlockedUrlException) {
            $this->recordSyncFailure($feedId, self::BLOCKED_URL_MESSAGE);

            return false;
        }

        if (!$items) {
            $this->recordSyncFailure($feedId, 'Feed returned no items');

            return false;
        }

        $changed = false;

        foreach ($items as $item) {
            if (empty($item->get_link())) {
                continue;
            }

            $feedItem = FeedItem::firstOrNew([
                'feed_id'      => $feedId,
                'feed_item_id' => $this->storedItemId($item->get_id()),
            ]);

            if (!$feedItem->exists) {
                try {
                    $feedItem->fill([
                        'title'        => $this->plainTitle($item->get_title()),
                        'description'  => $this->truncateDescription($item->get_description()),
                        'link'         => $item->get_link(),
                        'image_url'    => $this->feedReader->extractImageUrl($item),
                        'published_at' => $this->resolvePublishedAt($item),
                    ])->save();
                } catch (UniqueConstraintViolationException) {
                    // A concurrent sync stored this item first; its cache may
                    // already have been rebuilt without it, so still invalidate.
                }

                $changed = true;

                continue;
            }

            if ($backfillImages && $feedItem->image_url === null) {
                $imageUrl = $this->feedReader->extractImageUrl($item);
                if ($imageUrl) {
                    $feedItem->update(['image_url' => $imageUrl]);
                    $changed = true;
                }
            }
        }

        $feed->update(['last_synced_at' => Carbon::now(), 'last_error' => null]);

        if ($changed) {
            $this->forgetFeedItemsCache($feedId);
        }

        return true;
    }

    public function recordSyncFailure(string $feedId, string $error): void
    {
        Feed::where('id', $feedId)->update(['last_error' => Str::limit($error, self::MAX_ERROR_LENGTH)]);
    }

    public function forgetFeedItemsCache(string $feedId): void
    {
        $this->cache->forget([self::CACHE_KEY_FEED_ITEMS, $feedId]);
    }

    // Items without any date in the feed are treated as published at ingest time, since there is no better source.
    // Being explicit here matters: the retention job prunes on published_at, and a null would exempt the item forever.
    private function resolvePublishedAt(\SimplePie\Item $item): Carbon
    {
        $timestamp = $item->get_date('U');

        return $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : Carbon::now();
    }
}
