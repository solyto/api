# Brief: OPML import — one bad feed stops the import, double start, cleanup

type: feature
id: college
branch: feature/college_opml-import-one-bad-feed-stops-the-import-double-start-cleanup
date: 2026-11-14
author: Leander Muskalla

## What

Found in the review of the OPML import (0db14ab, 72b2c39):

1. **One bad feed stops the whole import.** `FeedService::subscribe()`
   (`app/Api/Feeds/Services/FeedService.php:104-124`) only expects
   `FeedException`. The remote feed title goes into `Feed::create()` without
   being truncated (`FeedService.php:120`; only the OPML title is cut, in
   `OpmlImportService`). A title over 255 characters gives a
   `QueryException` on MariaDB (strict mode). That ends
   `ImportFeedSubscriptions` (`$tries = 1`). State is only saved after each
   chunk of 25 (`OpmlImportService.php:167`), so feeds already subscribed in
   that chunk still show as `unreachable`, and every feed after the bad one
   is never tried.
2. **A double submit starts two jobs (plausible).** `select()`
   (`OpmlImportService.php:90-116`) checks `stage === Parsed` and then
   writes `Running`, with no lock between the two. Two quick calls (a
   double-tap or a client retry) both pass and dispatch two jobs.
   `feed_subscriptions(user_id, feed_id)` and `feeds.url` have no unique
   index, so both jobs insert, and the user gets duplicate subscriptions and
   duplicate items.
3. **No size cap on downloaded feed bodies (plausible).** The `Http::pool`
   requests (`OpmlImportService.php:151-157`) load whole bodies into memory,
   25 at a time, limited only by the 5 s timeout. Very large responses can
   run the queue worker out of memory.
4. **Import state survives account deletion.** `UserService::USER_CACHE_KEYS`
   (`app/Api/Users/Services/UserService.php:32-39`) doesn't include
   `feed_import`. A deleted user's import state, including their feed URLs
   and titles, stays in Redis for up to an hour.

## Why

1 is the realistic one: large OPML exports from other readers often
contain a few broken or odd feeds. 2 to 4 are robustness and cleanup.

## Steps

1. Truncate the remote title to the column length in `subscribe()`, where
   `plainTitle()` is applied. In `run()`, catch `Throwable` per feed, mark
   that feed `failed`/`unreachable`, log it, and continue. Save state after
   each feed, or at least in a `finally` per chunk.
2. Make the `Parsed` → `Running` change atomic: a `Cache::lock` on
   `feed_import:{user}` around check-and-set, or `Cache::add` on a
   per-user "running" marker. The second call gets a 409.
   - Optionally add unique indexes on `feeds.url` and
     `feed_subscriptions(user_id, feed_id)`. Check existing data for
     duplicates first.
3. Cap the response size in the pool requests: a `progress`/`on_headers`
   check like `RemoteCalDavClient`, or a `Content-Length` check plus a
   stream read limit.
4. Add `feed_import` to `USER_CACHE_KEYS`, or forget the key directly in
   the delete path if it's on a different store.
5. Tests:
   - A feed with a 300-character title in the middle of the list: later
     feeds are still imported, and earlier ones show as subscribed.
   - Two concurrent `select` calls start one job.
   - Account deletion clears the import state.

## Notes

- The SSRF guard on feed URLs is deliberately out of scope; it was left out
  by owner decision.
