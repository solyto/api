# Tasks: OPML import — one bad feed stops the import, double start, cleanup

id: college
analyst: analyst
date: 2026-11-14

<!-- Produced by @analyst from brief.md. -->

## Task breakdown

TASK-1: Cut the remote feed title to 255 characters (`mb_substr`, the `feeds.title` varchar length) in `FeedService::subscribe()` where `plainTitle($feedData['title'])` is applied, so an over-long remote title no longer throws a `QueryException`.
  files: app/Api/Feeds/Services/FeedService.php (new `MAX_TITLE_LENGTH = 255` constant next to the other limits), app/Api/Feeds/Tests/ (a FeedService test: 300-char remote title → stored title is 255 chars)
  depends: none
  risk: low — one-line change on a path every subscribe uses; `mb_substr` matches MariaDB's character-based varchar length.

TASK-2: In `OpmlImportService::run()`, catch `Throwable` per feed in both loops (known feeds and fetched feeds, including the `parseFeedData()` call), mark that feed `unreachable`, log a warning (user id, feed URL, exception class + message; no response bodies), and carry on; save state per chunk in a `finally` so a chunk's progress is never lost.
  files: app/Api/Feeds/Services/OpmlImportService.php, app/Api/Feeds/Tests/JobTest.php
  depends: none (TASK-1 removes the known cause; this is the general safety net)
  risk: medium — changes the failure model of the job, and two existing tests rely on the old one (see notes).
  notes:
    - Use the existing `Unreachable` status, not a new `failed` value: a new enum case changes the API contract (`FeedImportStateResource` OpenAPI enum) and needs a change in the separate frontend repo. Flagged as a choice, see "Open questions".
    - Keep the per-chunk save cadence: `JobTest` "fetches new feeds in chunks of 25 and writes progress after each" asserts it (`[0 => 25, 25 => 5]`). Saving after every feed would break that test for no real gain.
    - `JobTest` "finishes the import when the job fails part way through" makes `parseFeedData()` throw and expects `handle()` to throw. With the per-feed catch it no longer throws. Rewrite it: (a) a new test that the throwing feed becomes `unreachable`, the other feeds still get their outcome, and the stage is `finished`; (b) keep coverage for `fail()` by calling `$job->failed()` on a running import directly (no need to force a throw).
    - Log channel: follow whatever STANDARDS.md says for job/service logging; if nothing specific, the default channel `Log::warning`.

TASK-3: Add the brief's regression test: a 300-character remote title in the middle of the selected list — feeds before it show `subscribed`, the long-title feed is `subscribed` with a 255-char `Feed.title`, and feeds after it are still fetched and subscribed; plus a test where a feed throws a non-`FeedException` (e.g. mocked `createImportedSubscription` throwing `RuntimeException` for one URL) and the rest of the import still completes.
  files: app/Api/Feeds/Tests/JobTest.php
  depends: TASK-1, TASK-2
  risk: low — tests only; uses the existing `runningFeedImport()` / `feedImportStatuses()` helpers and `Http::fake`.

TASK-4: Make `select()`'s `Parsed` → `Running` change atomic with a non-blocking cache lock per user around the state read, stage check, `saveState()` and dispatch; if the lock is held, throw `FeedImportConflictException` (the controller already maps it to 409).
  files: app/Api/Feeds/Services/OpmlImportService.php, app/Api/Feeds/Tests/FeedImportApiTest.php (or OpmlImportServiceTest.php)
  depends: none
  risk: medium — concurrency code; a lock that isn't released (missing `finally`) would block the user's imports until its TTL.
  notes:
    - Take the lock on the `user_data` store (same store as the state; redis in prod, array in tests — both support locks): `Cache::store('user_data')->lock('feed_import_select_'.$userId, 10)`, `get()` non-blocking, `release()` in `finally`. `UserCacheService` has no lock method; don't add one (no new abstraction), call the store directly.
    - The lock name must not equal the state key `feed_import_{userId}`.
    - Test: hold the same lock from the test, call `POST /feeds/import/select`, expect 409 and `Queue::assertNotPushed(ImportFeedSubscriptions::class)`; then release and a call succeeds with exactly one job pushed. A real concurrent test isn't possible in Pest; this simulates the second caller. Also assert that a second sequential `select` returns 409 and only one job was pushed (already the stage check, but it pins the "one job" outcome).
    - No OpenAPI change needed: 409 is already documented for `select`.

TASK-5: Cap the size of each feed body fetched in the `Http::pool` requests: `on_headers` rejects a `Content-Length` over the cap, `progress` aborts once downloaded bytes exceed it (same pattern as `RemoteCalDavClient::request()`), and a `strlen($response->body())` check after the fact; an oversized feed becomes `unreachable`.
  files: app/Api/Feeds/Services/OpmlImportService.php, app/Api/Feeds/Tests/JobTest.php
  depends: TASK-2 (an exception thrown from the callbacks must end up as `unreachable`, not abort the run)
  risk: medium — Guzzle option callbacks inside a pool are easy to get subtly wrong, and `Http::fake` does not run `on_headers`/`progress`, so only the post-read length check is testable.
  notes:
    - Cap as a private constant next to `FETCH_TIMEOUT` (e.g. `MAX_FEED_BYTES = 5 * 1024 * 1024`). The value is an assumption — no existing feed size limit in the codebase to copy; `FeedReader`/SimplePie fetches for regular sync have none either and are out of scope.
    - In a pool, an exception from `on_headers`/`progress` rejects that request's promise; the result is not a `Response`, so the existing `instanceof Response` check already maps it to `unreachable`. Verify this rather than assume.
    - Test: `Http::fake` a body larger than the cap → that feed is `unreachable`, no `Feed` row is created, other feeds unaffected.

TASK-6: Add `feed_import` to `UserService::USER_CACHE_KEYS` (state lives on the `user_data` store as `[feed_import, userId]`, so the existing forget loop covers it) and extend the deletion test to assert it is cleared.
  files: app/Api/Users/Services/UserService.php, app/Api/Users/Tests/DeleteUserTest.php
  depends: none
  risk: low — one entry in an existing list; `cache_keys_flushed` count rises by one, no test asserts the exact number.
  notes:
    - In DeleteUserTest "deletes the user and all their data", store `['feed_import', $user->id]` alongside the existing seeded keys and assert `has()` is false afterwards.
    - Keep the list alphabetical (`feed_import` goes between `clipboard` and `games`).

TASK-7: Run `composer test` and `composer analyse` in the worktree; both must exit 0.
  files: none
  depends: TASK-1 … TASK-6
  risk: low — verification only.

## Out of scope (deliberately not tasked)

- Unique indexes on `feeds.url` and `feed_subscriptions(user_id, feed_id)` (brief step 2, marked optional). It needs a migration on the MariaDB connection and a check of production data for existing duplicates first, which can't be done from this worktree. TASK-4 fixes the reported race without it. Suggest a separate backlog entry if wanted.
- SSRF guard on feed URLs — excluded by owner decision (brief notes).

## Open questions

- Status for a feed that hits an unexpected exception: plan uses `unreachable` to keep the API enum unchanged. If a distinct `failed` status is wanted, it's a new `FeedImportStatus` case + OpenAPI enum + frontend change — say so and TASK-2 grows accordingly.
- `start()` has the same unlocked check-then-write shape (`Running` check, then writes `Parsed`). A `start` racing a `select` could reset a running import to `Parsed` and allow a second `select`/job. Not in the brief, so not tasked; it could reuse the TASK-4 lock in a couple of lines if the owner wants it.
- Size cap value (TASK-5) is an assumption; adjust if there's a preferred limit.
