# Verdict: OPML import — one bad feed stops the import, double start, cleanup

id: college
reviewer: reviewer
date: 2026-11-14

## Review

Reviewed `git diff main...HEAD` on `feature/college_opml-import-one-bad-feed-stops-the-import-double-start-cleanup`.
Code changes are limited to the files named in tasks.md; nothing changed that implementation.md does not mention.

TASK-1: PASS
notes: `FeedService.php:121` cuts `plainTitle(...) ?: $title` to `MAX_TITLE_LENGTH = 255` with `mb_substr`, matching the `feeds.title` `string()` column. `FeedServiceTest` checks that 300 multibyte chars become 255.

TASK-2: PASS
notes: `OpmlImportService::subscribe()` now takes a closure, so `parseFeedData()` runs inside the try block. It catches `Throwable`, logs a warning (user id, URL, exception class, message; no body) and returns `Unreachable`. The known-feeds loop and each fetch chunk save state in a `finally`, and the per-chunk save timing is unchanged (the chunk-of-25 test still passes). The old "fails part way through" test was split as planned: a parse that throws now gives `unreachable` and `finished`, and `failed()` is covered directly.

TASK-3: PASS
notes: `JobTest` adds the 300-char title test with before, long and after feeds (all `subscribed`, stored title is 255 chars, 3 subscriptions), plus a test where a `RuntimeException` from `createImportedSubscription` hits one URL and the other feeds are still subscribed. Without TASK-1, the long-title test would fail on its 255-char assertion, so it guards against regressions even on SQLite.

TASK-4: PASS
notes: `select()` takes a non-blocking `Cache::lock('feed_import_select_{user}', 10)` around read, check, save and dispatch. It releases the lock in `finally` and throws `FeedImportConflictException` (409) when the lock is held. The lock name is different from the state key. This uses the default store instead of the `user_data` store that tasks.md suggested. That is reasonable: the default store is redis in prod with a lock connection and array in tests, and both support locks. The tests cover a held lock (409, no job, then success with exactly one job) and a sequential double submit (one job).

TASK-5: PASS
notes: `CURLOPT_XFERINFOFUNCTION` aborts a transfer once the declared or downloaded bytes go over `MAX_FEED_BYTES` (5 MB). Guzzle's `CurlFactory` applies `options['curl']` last, so the callback takes effect. Laravel's pool turns a `RequestException` that still has a response into a `Response` (`PendingRequest.php:1218`). That supports the developer's reasoning for the checks in `isFetchedFeed()` (Content-Length header plus `strlen(body)`). The test covers both an oversized body and an oversized declared length: both feeds become `unreachable`, no `Feed` row is created for them, and the other feed is subscribed.

TASK-6: PASS
notes: `feed_import` was added to `USER_CACHE_KEYS` in alphabetical order. `DeleteUserTest` seeds the key and checks that it is cleared.

TASK-7: PASS
notes: The reviewer re-ran both checks in the worktree. `composer test` exited 0 (1534 tests, 5461 assertions; the warnings come from the environment's missing `.env`). `composer analyse` exited 0 with "[OK] No errors".

## Security

Not run. The SSRF guard is out of scope by owner decision. Logs include the feed URL and exception message but no secrets or response bodies.

## Overall

APPROVED

No blockers. The `.env.testing` entry in `git status` is the environment's `/dev/null` mount, not a change on this branch.
