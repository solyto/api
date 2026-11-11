# Tasks: `GET /feeds/items` does all the work on every page

id: break
analyst: analyst
date: 2026-11-10

## Scope

In scope: brief suggestions **S1, S2, S3**, plus a characterization test first,
because the brief's claim that "existing feed tests cover filtering and paging"
is wrong. `FeedApiTest` only checks the item count (2 items) and tenant
isolation. Nothing tests whitelist/blacklist filtering, ordering, offset/limit,
`limit=all`, `has_more`, `feed_counts`, or the `published_at` wire format.

Conditional: **S4** (TASK-6). It needs a new `UserCacheService::many()` method.
FeedService can't call `Cache::store()` itself because STANDARDS §8.1 forbids it.
It is the lowest-payoff item, so a reviewer or human can drop it without
affecting the rest.

Out of scope (not planned here):
- **S5** (per-user page cache or SQL paging + new `(feed_id, published_at)`
  index). The brief says a human must decide. It needs a migration and changes
  how keyword filtering works.
- Replacing `FeedItem::firstOrNew` with one `whereIn(...)->pluck()` per feed in
  `syncFeed`. The brief notes this but doesn't list it as a suggestion. It can
  become a follow-up backlog entry.
- Capping `limit=all` (cause 6). The brief calls it a legitimate client option
  and proposes no change.

## Task breakdown

TASK-1: Add characterization tests for `GET /api/v1/feeds/items` that lock down the current response body before any refactor.
  files: app/Api/Feeds/Tests/FeedApiTest.php (or a new app/Api/Feeds/Tests/FeedItemsListTest.php)
  depends: none
  risk: low. Tests only, but they are the safety net for TASK-2..4.
  notes: Cover at least: items from 2+ subscribed feeds come back merged and
    ordered by `published_at` desc. Whitelist keeps only matches, case-insensitive,
    on title + description, and trims keywords with spaces like `"php, laravel"`.
    Blacklist drops matches. Whitelist and blacklist work together.
    `offset`/`limit` slice correctly. `limit=all` returns everything and
    `has_more` is false. `has_more` is true when a page is full.
    `meta.feed_counts` counts per feed *after* filtering and across all pages.
    `data.N.published_at` has the exact serialized format (Carbon ISO-8601,
    e.g. `2026-11-08T12:00:00.000000Z`). Also lock down the
    `POST /feeds/test` cached-items path's `published_at` format, because
    TASK-2 changes what `getFeedItems()` returns. Use explicit `published_at`
    values (not the factory's random ones) so ordering is deterministic. Follow
    docs/TESTING.md.

TASK-2: Cache raw rows instead of Eloquent models in `getFeedItems()` under a new cache key, sort on the raw `published_at`, and re-hydrate only the returned page.
  files: app/Api/Feeds/Services/FeedService.php
  depends: TASK-1
  risk: medium. It changes what the cache holds and what `getFeedItems()` returns. A wrong format would silently change `published_at` in the API response.
  notes:
    - Rename the key constant value, e.g. `CACHE_KEY_FEED_ITEMS = 'feed_items_v2'`,
      so old serialized-model entries are ignored. They expire on their own
      within the current 1 h TTL. `forgetFeedItemsCache()` uses the constant,
      so `DeleteOldFeedItems` needs no change.
    - Cache `FeedItem::where(...)->orderBy('published_at','desc')->toBase()->get()`,
      which is a Collection of stdClass. Keep it a Collection object, not a bare
      array. `UserCacheService::get()` treats a falsy value as a miss
      (STANDARDS §8.3), so an empty `[]` would make an empty feed re-query the
      DB on every request.
    - `filterFeedItems` and `countBy('feed_id')` already work on stdClass
      properties. `sortByDesc('published_at')` then compares
      `Y-m-d H:i:s` strings, which sort lexically in time order. Check that
      NULL `published_at` rows still sort last, as they do today.
    - After slicing, rebuild the page with
      `FeedItem::hydrate($page->map(fn ($r) => (array) $r)->all())`. That keeps
      `FeedItemResource` and the `published_at` serialization byte-identical.
      `limit=all` hydrates everything, which costs no more than today.
    - `getTestFeedItems()` also calls `getFeedItems()` and returns
      `$item->published_at` (a Carbon today). Keep its output identical, e.g. by
      hydrating there too or parsing to Carbon.
    - Update the `Collection` return-type generics/PHPDoc so `composer analyse`
      passes.
    - Re-run the 9 000-item benchmark the brief describes, ad hoc and not
      committed, and record the before/after numbers in implementation.md.

TASK-3: Parse whitelist and blacklist keywords once per subscription in `filterFeedItems()`, and skip filtering when neither list is set.
  files: app/Api/Feeds/Services/FeedService.php
  depends: TASK-1 (can be done before or after TASK-2, same file)
  risk: low. It's a pure refactor of one method, and the TASK-1 tests pin its behavior.
  notes: Keep exact current semantics. Keywords are lowercased and trimmed.
    An empty keyword left by a trailing comma (`"php,"`) becomes `''`, and
    `str_contains($text, '')` is true. Today that means a whitelist with a
    trailing comma matches everything and a blacklist with one drops everything.
    Keep that as-is, don't "fix" it silently. If it looks like a bug, note it
    in implementation.md as a follow-up.

TASK-4: Make `syncFeed()` forget the feed-items cache only when the sync stored a new item or backfilled an image.
  files: app/Api/Feeds/Services/FeedService.php
  depends: none
  risk: low. Every other path that writes feed_items (`DeleteOldFeedItems`) already forgets the key itself.
  notes: Set `$changed = true` after a successful new-item `save()`, after a
    backfill `update()`, and in the `UniqueConstraintViolationException` branch
    (conservative: a concurrent writer added a row). Keep the
    `last_synced_at`/`last_error` update unconditional.

TASK-5: Raise `CACHE_TTL_FEED_ITEMS` above the hourly sync interval.
  files: app/Api/Feeds/Services/FeedService.php
  depends: TASK-4
  risk: low. Correctness depends on invalidation (TASK-4 + `DeleteOldFeedItems`), not on the TTL.
  notes: The brief gives no value. 86400 (same as `CACHE_TTL_USER_FEEDS`) is
    a reasonable choice. Expired items stay cached until the 02:00
    `DeleteOldFeedItems` run, which is also when they leave the DB today, so
    nothing changes for users.

TASK-6 (conditional, see Scope): Batch the per-subscription cache reads into one `many()` call.
  files: app/Shared/Services/UserCacheService.php, app/Api/Feeds/Services/FeedService.php
  depends: TASK-2
  risk: medium. It adds a method to a shared service used by every module, and it must match `get()`'s miss/logging semantics.
  notes: Add `UserCacheService::many(array $identifierLists): array` that wraps
    `Cache::store(...)->many()`. It treats falsy values as misses, like `get()`,
    and logs failures with keys only. In `getAggregatedItems`, read all
    `feed_items_v2_{id}` keys at once, then fill misses with `getFeedItems()`
    (`remember`). Add a unit test for `many()` under tests/Unit. This adds a
    method to an existing class, not a new abstraction. Still, drop this task
    if the reviewer judges the shared-service change not worth about N
    Redis round-trips.

TASK-7: Add a test that syncing a feed whose items all already exist does not forget the feed-items cache, and one that a sync storing a new item does forget it.
  files: app/Api/Feeds/Tests/FeedServiceTest.php
  depends: TASK-4
  risk: low. Tests only.
  notes: The tests' `user_data` store is an in-memory array store
    (tests/TestCase.php). Seed the cache by calling `getFeedItems()`, then
    assert with `Cache::store('user_data')->has('feed_items_v2_'.$feed->id)` or
    a mocked `UserCacheService`. Reuse the existing `feedItemDouble()` helper.
    Also cover the backfill path (`backfillImages: true` with a new image URL
    → forgets).

## Verification

`composer test` and `composer analyse` must both exit 0. TASK-1's tests must
pass unchanged before and after TASK-2..6.
