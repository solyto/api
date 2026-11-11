# Brief: `GET /feeds/items` does all the work on every page

type: feature
id: break
branch: feature/break_get-feeds-items-does-all-the-work-on-every-page
date: 2026-11-10
author: Leander Muskalla

Source report: `docs/reports/feed-items-pagination-performance-2026-11-08.md`

## Summary

`FeedController::listItems` → `FeedService::getAggregatedItems` loads **every**
cached item of **every** subscribed feed, filters, sorts and only then slices
out 50. Each scroll page pays the full cost again. Measured on a synthetic 9 000-item
set (e.g. 30 feeds × 300 items over the 5-day retention), one page costs about
**0.7 s of PHP CPU** before JSON encoding. Most of that comes from two avoidable
choices: caching full Eloquent models, and sorting by a `datetime`-cast attribute
(Carbon object comparison). Hourly sync also invalidates every feed's cache
whether or not anything changed.

No earlier report covers this. The closest is the code-quality audit §3.9, which is
about `UserCacheService` falsy misses and is unrelated.

## Where the time goes

`app/Api/Feeds/Services/FeedService.php`:

```php
foreach ($subscriptions as $subscription) {
    $feedItems = $this->getFeedItems($subscription->feed_id);   // 1 Redis GET + unserialize per feed
    $items = $items->merge($this->filterFeedItems($feedItems, $subscription));
}
$counts = $items->countBy('feed_id')->all();
$sorted = $items->sortByDesc('published_at');                  // Carbon compare
$page   = $sorted->slice($offset, $limit)->values();
```

Benchmark (`FeedItem` models with realistic field sizes: 1 000-char description,
URLs, UUIDs; PHP 8.4 in the dev container; mean of 5 runs):

| Step (9 000 items)                                      | Today            | With plain arrays |
|---------------------------------------------------------|------------------|-------------------|
| Serialized cache payload (sum over all feeds)           | 37.7 MB          | 13.3 MB           |
| `unserialize`                                           | ~165 ms          | ~65 ms            |
| `filterFeedItems` (whitelist + blacklist set)           | ~165 ms          | n/a (see 3)       |
| `sortByDesc('published_at')`                            | **~347 ms**      | **~13 ms**        |

Redis network transfer of the ~38 MB is not counted here, and it is paid per page.

Causes:

1. **Models in the cache.** `getFeedItems()` caches `FeedItem::…->get()`.
   Serialized models carry `attributes` *and* `original` plus model metadata,
   which makes them about 2.8× bigger than the data, and every page unserializes all of them.
2. **Sorting on a cast attribute.** `published_at` is cast to `datetime`, so
   `sortByDesc` builds 9 000 Carbon instances and `asort` compares objects.
   Sorting on the raw string/timestamp is ~25× faster.
3. **Keyword parsing per item.** `filterFeedItems` runs `explode` +
   `array_map('strtolower')` + `collect()` for the whitelist and the blacklist
   **inside** the per-item closure, so it re-parses the same keywords for every item.
4. **N sequential Redis GETs.** One `get` per subscription, instead of one `many()`.
5. **Hourly invalidation regardless of change.** `syncFeed()` always calls
   `forgetFeedItemsCache()`, even when every item already existed. With a 1 h
   TTL and an hourly sync, the per-feed cache is cold for most first requests
   of the hour, and the miss path loads the whole feed from MariaDB again.
6. **`limit=all`** returns the full set unbounded. It is a legitimate client option,
   but it means the worst case is not capped.

The sync side has the same per-row pattern: `FeedItem::firstOrNew` issues one
SELECT per RSS item per feed per hour. The `(feed_id, feed_item_id)` unique index
keeps each one cheap, but one `whereIn('feed_item_id', …)->pluck()` per feed
would replace ~N queries with one.

## Suggestions (ordered by payoff / effort)

**S1: Cache plain arrays, sort on the raw value (small, high payoff).**
In `getFeedItems()` cache
`->get([...columns])->map->only([...])->all()`, or `toBase()->get()`, with
`published_at` kept as the DB string or a Unix int. Sort with
`sortByDesc('published_at')` on those scalars. `FeedItemResource` reads via
`$this->…`, so it needs either `JsonResource` over arrays (array access works) or
a small re-hydration of just the 50 sliced rows (`FeedItem::hydrate`).
Expected: ~0.5 s → ~0.1 s per page at 9 000 items.
Watch out: existing serialized-model cache entries must be ignored or flushed once on deploy
(change the cache key, e.g. `feed_items_v2`).

**S2: Parse keywords once per subscription (trivial).**
Move the `explode`/`strtolower`/`trim` out of the closure in
`filterFeedItems`, and skip `filter()` entirely when neither list is set.

**S3: Only invalidate when the sync changed something (trivial).**
Track `$changed` in `syncFeed()` (new row saved, or image backfilled) and call
`forgetFeedItemsCache()` only then. Then raise `CACHE_TTL_FEED_ITEMS` above the
sync interval. Invalidation already covers correctness, and the
`DeleteOldFeedItems` job already forgets the affected feeds.

**S4: Batch the cache reads (small).**
Read all `feed_items_{id}` keys with one `Cache::store('user_data')->many()`.
`UserCacheService` has no `many()` today, so this would be a new method. Check
it against "no new abstractions" before doing it.

**S5 (larger, for decision): per-user aggregated page cache or SQL paging.**
If S1–S4 are not enough, either cache the user's filtered, sorted id list +
`feed_counts` (invalidated by subscription changes and by syncs of those feeds),
or move paging into SQL (`whereIn feed_id … orderByDesc published_at
limit/offset`) for subscriptions without keyword filters. The second would need an
index on `feed_items (feed_id, published_at)`. Today only the unique
`(feed_id, feed_item_id)` index exists, and `DeleteOldFeedItems` also scans on
`published_at` with no index. `feed_counts` would then need its own
`GROUP BY` query. This changes semantics around keyword filtering, so a human
should decide.

## Testing notes (for whoever implements)

- Existing feed tests in `app/Api/Feeds/Tests` cover filtering and paging. S1–S3
  should not change any response body, so keep those tests green unchanged.
- Add a test for S3: syncing a feed whose items all exist does not forget the
  cache key.
- Do the benchmark again with a seeded 9 000-item user after S1/S2 to confirm.

## Limitations

- Item volume is assumed, not measured. Real per-user item counts from
  production (`SELECT feed_id, COUNT(*) FROM feed_items GROUP BY feed_id`,
  joined to subscriptions) would show whether 9 000 is typical or a heavy user.
  Cost scales roughly linearly, so 3 000 items is ~⅓ of the numbers above.
- Timings are from the dev container, not production hardware.
