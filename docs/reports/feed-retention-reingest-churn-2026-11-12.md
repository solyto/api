# Report: Feed retention deletes items that the next sync re-inserts

date: 2026-11-12
read: true
branch: `main`, tree at `ff71085`
scope: Interaction between the nightly `DeleteOldFeedItems` job and the hourly `SyncFeed` job. Written for the maintainer. No application code, config or migrations were changed. A throwaway probe test was run and then deleted.

## Summary

**Every item that is still in a feed but older than the retention window (5 days) is deleted at 02:00 and re-inserted by the 03:00 sync, every day.** `syncFeed` decides "new or known" only by whether a row exists (`FeedService.php:415-420`). Retention removes the row, so the item counts as new again. Each re-insert runs `extractImageUrl`, which can fetch the full article page over HTTP, and then clears the feed's item cache.

This hits slow-moving feeds hardest: blogs, release notes, and weekly newsletters keep posts in the feed for weeks or months. For them, nearly **every** item goes through a full delete and re-ingest each day. Retention also does nothing for these items, because they are back in the users' lists an hour after they are deleted.

**Recommendation:** prune on "last seen in the feed" instead of on `published_at` alone (option B, §3). Do it together with a single batched lookup per sync instead of one `SELECT` per item (§2.2).

## 1. Measured

Throwaway Pest probe (SQLite test DB). It used one feed with 20 items, all published 10–29 days ago, and `FeedReader` was mocked. It ran three cycles of sync → `DeleteOldFeedItems`, then one more sync:

| step | rows in `feed_items` |
|---|---|
| day 0 after sync | 20 |
| day 0 after retention | 0 |
| day 1 after sync | 20 |
| day 1 after retention | 0 |
| day 2 after sync | 20 |
| day 2 after retention | 0 |

- `extractImageUrl` calls over the 4 syncs: **80**. Each sync re-ingested all 20 items.
- Queries for one sync where all 20 items were already stored: **21**. That is one `firstOrNew` `SELECT` per item, plus the `Feed` lookup and update.

## 2. Why it costs

### 2.1 Daily re-ingest

For each re-inserted item, `syncFeed` (`FeedService.php:420-434`) does the following:

1. `extractImageUrl` (`FeedReader.php:60-109`) checks the feed's own media tags and HTML first. If none of them has an image, it does an **HTTP GET of the article page** (`extractOgImage`, 5 s timeout) and regex-scans the body. Text-only feeds, such as many blogs and link aggregators, take this path for every item. Costs:
   - One outbound page fetch per stale item per day, for every subscribed feed.
   - A sync can hit `SyncFeed::$timeout = 240`. 50 items with slow hosts × 5 s is already 250 s, which kills the job, and `$tries = 2` runs it all again.
   - Publishers see the same "FeedBot/1.0" agent fetch their old articles every day. That is a fair reason for them to block the server's IP.
2. An `INSERT` per item.
3. `forgetFeedItemsCache` for the feed. The next page load for every subscriber rebuilds the cache from the DB, which is the cost that job `break_get-feeds-items-does-all-the-work-on-every-page` worked to keep down.

Users also see items in their lists that are older than the retention window, and those items get new row ids each day.

### 2.2 Per-item lookup on every hourly sync

Even with no new items, each sync runs one `SELECT` per item in the feed (`firstOrNew`, `:415`). That is about 20–100 queries per feed per hour, multiplied by every subscribed feed. One `pluck('feed_item_id')` with `whereIn` over the feed's current ids returns the same answer in a single query.

## 3. Options

### A. Skip items older than the cutoff at ingest

In `syncFeed`, `continue` before `firstOrNew` when `resolvePublishedAt($item)` is older than `now() - retention_days`.

- **Pro:** about 3 lines and no migration. It stops the churn and the extra fetches, and it also skips the per-item `SELECT` for old items.
- **Con:** this is a visible behaviour change. A slow blog whose newest post is 2 weeks old would show **nothing** after subscribing. Today it shows its posts, as a side effect of the churn. The "test feed" preview (`getTestFeedItems`) would still show items, so the user sees items in the preview and none after subscribing.

### B. Prune on "last seen", not on publish date (recommended)

- Add a nullable `last_seen_at` timestamp to `feed_items`. This is the default MariaDB connection, so the DAV schema is not touched.
- In `syncFeed`:
  - Run one `whereIn` lookup for the ids in the current feed.
  - Insert the missing items, as today.
  - Run one `UPDATE … SET last_seen_at = now() WHERE feed_id = ? AND feed_item_id IN (…)`. To cut write volume, this can be limited to rows whose `last_seen_at` is older than a few hours.
- `DeleteOldFeedItems` keeps an item while `last_seen_at` is newer than the cutoff. It prunes an item only when it is older than the window **and** no longer in the feed (`published_at < cutoff AND (last_seen_at IS NULL OR last_seen_at < cutoff)`).

Effects:
- **Pro:** the churn and the repeated article fetches stop. Slow feeds keep showing their posts, and stored rows stay bounded by feed size + retention window. The sync drops from about N+2 queries to about 3 plus one insert per genuinely new item.
- **Con:** it needs a migration (show it before running it, per AGENTS.md). Existing rows start with `last_seen_at = NULL`, so they keep today's behaviour until their first sync after deploy. That needs no backfill.
- **Test:** extend `JobTest`/`FeedServiceTest` with the probe above, turned into an assertion: run sync → retention → sync, then check that the row count is unchanged and that `extractImageUrl` was called once per item.

### C. Raise `FEEDS_RETENTION_DAYS`

This only moves the threshold. Any item that stays in a feed longer than the new window still churns daily.

## 4. Side note (security, not in scope here)

`extractOgImage` fetches whatever `<link>` a feed item carries, and `FeedReader::getItems` fetches whatever URL a user subscribes to (`StoreFeedSubscriptionRequest` only checks `url`). Neither blocks private or loopback addresses, so any registered user can make the server request internal hosts (SSRF). The feed path even returns the parsed titles and links through `getTestFeedItems`. No report or backlog entry covers this for feeds. It would make a good subject for a separate report.
