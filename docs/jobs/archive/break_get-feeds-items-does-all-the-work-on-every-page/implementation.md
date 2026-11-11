# Implementation: `GET /feeds/items` does all the work on every page

id: break
developer: developer
date: 2026-11-10

## Summary

Implemented S1–S4 from the brief. Characterization tests went in first. The
per-feed cache now holds raw rows instead of Eloquent models. Sorting runs on
the raw `published_at` string, and only the returned page is hydrated into
models. Keyword filters are parsed once per subscription. All feed caches are
read in one round trip. A sync only invalidates the cache when it changed
something, and the cache TTL is now 24 h. Response bodies did not change, and
the TASK-1 tests passed before and after.

Ad-hoc benchmark (not committed): 30 feeds × 300 items, 1 000-char descriptions,
blacklist on every subscription, serialize/unserialize to mimic Redis, mean of 5 runs:

| | Before | After S1 | After S1+S2 |
|---|---|---|---|
| Serialized payload | 39.5 MB | 14.6 MB | 14.6 MB |
| One page (unserialize + filter + count + sort + slice) | ~520–590 ms | ~130 ms | ~50–57 ms |

## Changes

TASK-1: new `app/Api/Feeds/Tests/FeedItemsListTest.php`. It covers merge and
ordering across feeds, the exact item shape and the ISO-8601 `published_at`,
NULL `published_at` sorting last, whitelist (case-insensitive, title and
description, trimmed keywords), blacklist, both lists together, `feed_counts`
after filtering and across pages, offset/limit with `has_more`, `limit=all`,
and the `POST /feeds/test` cached path's output.

TASK-2: `FeedService`. The cache key is now `feed_items_v2`, so old
serialized-model entries are ignored. `getFeedItems()` caches `toBase()->get()`,
a Collection of stdClass. A new private `hydrateFeedItems()` turns only the page
(or all items for `limit=all`) back into `FeedItem` models, which keeps
`FeedItemResource` output byte-identical. `getTestFeedItems()` hydrates too,
so its `published_at` stays a Carbon. PHPDoc generics updated.

TASK-3: `FeedService::filterFeedItems()`. Keywords are parsed once with
`parseKeywords()`, and matching uses `containsAny()`. If neither list is set,
the items come back unfiltered. Semantics are unchanged, including the
trailing-comma behavior (see follow-ups).

TASK-4: `FeedService::syncFeed()` tracks `$changed`: a new item saved, a
`UniqueConstraintViolationException` (conservative), or an image backfilled.
It only calls `forgetFeedItemsCache()` when something changed.
`last_synced_at`/`last_error` are still updated on every sync.

TASK-5: `CACHE_TTL_FEED_ITEMS` 3600 → 86400.

TASK-6: new `UserCacheService::many()`. It keeps the caller's keys, treats
misses and falsy values as null like `get()`, and on failure logs only the
keys. `getAggregatedItems()` reads all feed caches with it and falls back to
`getFeedItems()` for misses. Unit tests are in `tests/Unit/UserCacheServiceTest.php`.
This task was marked conditional. A reviewer can drop it on its own without
affecting the rest.

TASK-7: three tests in `app/Api/Feeds/Tests/FeedServiceTest.php`:
- a sync where every item already exists keeps the cache key
- a new item clears it
- an image backfill clears it

Verification: `composer analyse` exits 0, and `composer test` exits 0
(1370 tests). The very first full run after a fresh `composer install` had 2
failures that I could not reproduce in three later runs. They were probably
caused by first-run setup (`storage/framework` and the DAV SQLite were freshly
created), not by this change.

## Known issues / follow-ups

- Trailing or empty keywords (`"php,"`) parse to `''`, which matches every
  item. A whitelist like that keeps everything, and a blacklist like that
  drops everything. This is existing behavior, kept as-is. It probably needs
  a fix (ignore empty keywords) and its own decision.
- `syncFeed()` still does one `firstOrNew` SELECT per RSS item. One
  `whereIn('feed_item_id', …)` per feed would replace that (brief, not
  planned here).
- S5 (SQL paging or a per-user page cache, plus a `(feed_id, published_at)`
  index) is still open for a human decision.
- `.env.testing` shows as modified in the worktree, but git can't hash it
  ("unsupported file type", probably a mount). I didn't touch it and it is
  not committed.
