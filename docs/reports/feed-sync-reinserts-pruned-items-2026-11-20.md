# Report: Feed sync re-inserts the items the retention job just deleted

date: 2026-11-20
read: false
branch: `main`, tree at `ca9c485`
scope: Performance of the hourly feed sync (`SyncFeed` → `FeedService::syncFeed`) and how it interacts with the nightly `DeleteOldFeedItems` job. Written for the maintainer. No application code, config, migrations, or DAV wiring/schema were changed. A throwaway Pest probe was run and deleted.

## Summary

**The retention job and the sync undo each other every day.** `DeleteOldFeedItems` (daily at 02:00, `routes/console.php:9`) deletes items with `published_at` older than `feeds.retention_days` (default **5**, `config/feeds.php:17`). Most feeds list more than 5 days of items. Blogs, release notes and podcasts often list weeks or months. The next hourly sync sees those items in the feed, can't find them in the table (`FeedItem::firstOrNew`, `FeedService.php:522-525`), treats them as new and inserts them again (`:527-538`), keeping their old `published_at`. They are deleted again the next night. This repeats every day for as long as the item stays in the feed.

Each re-insert pays the full new-item cost:

- `FeedReader::extractImageUrl` (`FeedService.php:533`). For items with no image in the feed this ends in `extractOgImage`: **an outbound HTTP fetch of the article page** with a 5 s timeout (`FeedReader.php`, `OG_IMAGE_TIMEOUT`). The docblock at `FeedService.php:459-464` says image lookups "only happen for items being stored for the first time". Because of the prune, every old item counts as "first time" again each day.
- an `INSERT` per item, then a `DELETE` of the same row the next night
- `forgetFeedItemsCache` (`:557-559`), so every user subscribed to that feed rebuilds the cached item list on the next page load

Effects that users and operators can see:

- **Retention isn't actually applied.** Pruned items are back in the list from about 03:00 until 02:00 the next day, so in practice the window is "whatever the feed lists".
- **Risk of sync timeouts.** A feed with 50 image-less items can spend up to 50 × 5 s on og:image lookups in one run. `SyncFeed::$timeout` is 240 s (`SyncFeed.php:19`). The worst case is slow article hosts on the 03:00 run.
- **Outbound traffic to publishers** for pages already fetched the day before, every day.

## Measured

Probe: one feed with 20 items dated today, 3, 6, …, 57 days ago, no images (so each new item does an og:image lookup). `Http::fake`, SQLite in-memory. Each "day" = `syncFeed` then `DeleteOldFeedItems`:

| day | rows after sync | rows after prune | outbound HTTP requests in the sync |
|---|---|---|---|
| 0 | 20 | 2 | 21 (1 feed + 20 og:image) |
| 1 | 20 | 2 | **19** (1 feed + **18 og:image** for re-inserted items) |
| 2 | 20 | 2 | **19** |

From day 1 on, 18 of 20 rows are deleted and re-inserted every day, and each one gets a fresh og:image fetch. Across all subscribed feeds, the cost per day is roughly *(items listed in the feed − items from the last 5 days)* × (insert + delete + possible page fetch).

## Suggestion

**1. (Recommended, small) Don't ingest items that are already past the retention cutoff.** In `syncFoundFeed`, compute the cutoff once (`Carbon::now()->subDays((int) config('feeds.retention_days'))`, the same expression as `DeleteOldFeedItems.php:36`). Skip an item **before** `firstOrNew` when it has a date older than the cutoff:

```php
$publishedAt = $this->resolvePublishedAt($item);

if ($publishedAt->lt($cutoff)) {
    continue;
}
```

Then pass `$publishedAt` into the `fill()` instead of calling `resolvePublishedAt` a second time. Benefits:

- no schema change, no new dependency, no API shape change
- it also saves the `firstOrNew` SELECT for every old item on **every** hourly run, not just the daily re-insert
- the retention setting then means what `config/feeds.php` says

Behaviour change to accept: a new subscription to a feed whose items are **all** older than the window will show an empty list. Today it shows the old items until the next 02:00 prune, so this only makes the existing end state visible earlier. Keep the `subscribe()` check that the feed has items (`FeedService.php:161`) as it is: it validates the fetched feed, not what gets stored. One more choice: putting the cutoff logic in a small shared spot (e.g. a `FeedItem::retentionCutoff()` static) keeps the job and the sync from drifting apart.

Tests (in `app/Api/Feeds/Tests/FeedServiceTest.php` / `JobTest.php`):

- an item dated before the cutoff isn't stored and gets no og:image fetch (`Http::assertSentCount`)
- sync → prune → sync leaves the table unchanged and sends only the feed request on the second sync

**2. (Optional, same job) One lookup instead of N.** Before the loop, load the existing ids once:

```php
FeedItem::where('feed_id', $feedId)->whereIn('feed_item_id', $ids)->pluck('image_url', 'feed_item_id')
```

That replaces one `firstOrNew` query per item. It's only worth doing together with (1). The `UniqueConstraintViolationException` catch stays, for concurrent syncs.

## Follow-up (not covered by the fix above)

**Undated items come back as "new" every 5 days.** Items with no date get `published_at = now()` (`resolvePublishedAt`, `FeedService.php:579-584`). After 5 days they're pruned. If the feed still lists them, the next sync inserts them again with a **new** `published_at = now()`, so they jump back to the top of the user's list as fresh items. Suggestion (1) can't catch them because they have no date to compare. Two ways to fix it:

- keep undated items: prune them by `created_at` only once they have also left the feed
- remember pruned `(feed_id, feed_item_id)` pairs for a while

Both are a bit larger and need a decision. How often this happens depends on how many subscribed feeds leave out `pubDate`/`updated`. A quick count on production would tell: `SELECT COUNT(*) FROM feed_items WHERE published_at = created_at`.
