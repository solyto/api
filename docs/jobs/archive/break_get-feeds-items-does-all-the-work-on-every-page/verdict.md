# Verdict: `GET /feeds/items` does all the work on every page

id: break
reviewer: reviewer
date: 2026-11-10

## Review

Reviewed `git diff main...HEAD` on `feature/break_get-feeds-items-does-all-the-work-on-every-page`.
Every change in the diff is listed in implementation.md. Nothing is out of scope.

Ran in the worktree:
- `composer test`: exit 0, 1370 tests passed. Every test shows a `file_get_contents(...)`
  warning. It comes from the environment: `.env.testing` is a mount that can't be read
  normally, and it happens on every test, not only the ones this job touched.
- `composer analyse`: `[OK] No errors`.

TASK-1: PASS
notes: `app/Api/Feeds/Tests/FeedItemsListTest.php` covers everything tasks.md asked for:
  - merge and ordering across feeds
  - the exact item shape and ISO-8601 `published_at`
  - NULL `published_at` sorting last
  - whitelist: case-insensitive, matches title and description, trims keywords
  - blacklist, and both lists together
  - `feed_counts` after filtering and across pages
  - offset/limit with `has_more`
  - `limit=all`
  - the `POST /feeds/test` cached path

  All dates are explicit, so the ordering tests are deterministic.

TASK-2: PASS
notes: `FeedService.php`:
  - The cache key is now `feed_items_v2`.
  - `getFeedItems()` caches `toBase()->get()`, a Collection object. An empty feed is
    therefore still a cache hit, as STANDARDS §8.3 requires.
  - The sort runs on the raw `Y-m-d H:i:s` string. NULL still sorts last, because
    null compares as `""`, and a test covers it.
  - `hydrateFeedItems()` rebuilds only the page (all items for `limit=all`), so
    `FeedItemResource` output is unchanged.
  - `getTestFeedItems()` also hydrates, so `published_at` is still a Carbon.

  Only `FeedService` calls `getFeedItems`/`filterFeedItems`, so no other caller is
  affected by the stdClass rows. `DeleteOldFeedItems` uses `forgetFeedItemsCache()`,
  which uses the constant, so it picks up the new key.

TASK-3: PASS
notes: `parseKeywords()` runs once per subscription, and `filter()` is skipped when
  neither list is set. The `trim(strtolower(...))` behavior and the empty-keyword
  behavior on a trailing comma are unchanged. That behavior is noted in
  implementation.md as a follow-up, as tasks.md asked.

TASK-4: PASS
notes: `$changed` is set:
  - after saving a new item
  - in the `UniqueConstraintViolationException` branch (conservative; the comment
    explains why)
  - after a backfill `update()`

  The cache is forgotten only when `$changed` is true. `last_synced_at`/`last_error`
  are still updated on every sync.

TASK-5: PASS
notes: `CACHE_TTL_FEED_ITEMS` 3600 → 86400.

TASK-6: PASS
notes: `UserCacheService::many()` matches `get()`:
  - falsy values come back as null
  - on failure it logs only the keys
  - it keeps the caller's array keys

  `getAggregatedItems` indexes `$cached` by the subscription collection's keys, which
  match the `foreach` keys. It falls back to `getFeedItems()` (`remember`) on a miss.
  This adds a method to an existing service, not a new abstraction, and it keeps
  STANDARDS §8.1 (no `Cache::` in module services). Unit tests are in
  `tests/Unit/UserCacheServiceTest.php`.

TASK-7: PASS
notes: `FeedServiceTest.php` has the three tests tasks.md asked for:
  - every item already exists → the cache key is kept
  - a new item → the key is forgotten
  - a backfilled image → the key is forgotten

## Security

None run. Nothing in the diff touches auth, input handling or secrets.

## Overall

APPROVED

Nothing blocks the merge.
