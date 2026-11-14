# Verdict: import feeds from an OPML file (api)

id: harvest
reviewer: reviewer
date: 2026-11-13

## Review

Uncommitted working tree on `main` (owner decision), reviewed with
`git diff -- app routes` plus the untracked files. All changes belong to the
eight tasks. No migration, no config change, `ImportFeeds.php` and the
Calendar/Contacts import code are untouched. `.env.testing` shows as `M` only
because it is a character device in this sandbox; it is not part of the job.
Existing tests changed only by appended blocks (`FeedReaderTest`, `JobTest`).

TASK-1: PASS
notes: `FeedReader::parseFeedData` (`FeedReader.php:49-70`) uses a fresh `SimplePie` with `set_raw_data`, cache off, and returns the `getFeedData` shape or `false`. `getFeedData`/`getItems` are unchanged. The test that parsed content does not leak into a later `getFeedData` covers the shared-instance risk.

TASK-2: PASS
notes: I compared `subscribe()` (`FeedService.php:104-140`) line by line with the old `createSubscription` body at HEAD. It is identical apart from `$fetchFeedData()` replacing the inline `getFeedData($url)` call. That closure still runs only when the `Feed` row is missing. The signature, exception types and messages, cache forget and `SyncFeed` dispatch are unchanged. `createImportedSubscription` passes `null`/`false`/array correctly (`null` → `false` → `FeedException` only when no row exists). `plainTitle` is public, and the docblock gives the reason. No single-subscribe test changed.

TASK-3: PASS
notes: There are three plain exceptions, one per status. The DTO has only an enum property and an array of scalar/enum values, so it serialises into Redis (`cache.serializable_classes` is not set). `FeedImportStateResource` has one `return [ … ]` literal, the `FeedImportState` schema and `@mixin`. Its output matches the contract exactly: `stage`, `total`, `processed`, and `feeds[].url/title/already_subscribed/status`. Enum values are the brief's strings. `processed()` excludes both `null` and `pending`.

TASK-4: PASS
notes: The cache use is correct: `UserCacheService`, key `[feed_import, userId]`, and `private const` key and TTL. The 409/404/422 decisions are made in the service. `already_subscribed` is one join query scoped by `forUser`. `select` filters only against `$state->feeds` (and drops `already_subscribed`), so the server can only fetch URLs that came from the parsed state. Dedup, 255-char and http(s) filters, scraper types, the `xmlUrl`/`xmlURL`/`url` order, the title fallback and recursion all match steps 2 and 4-11. The `DOMDocument::loadXML` deviation is sound: same flags, no `NOENT`/`DTDLOAD`/`PARSEHUGE`.
Re-review: the first pass found that the byte-regex DTD check missed UTF-16/UTF-32 input. That is now fixed. `parse()` (`OpmlImportService.php:214-216`) throws `FeedImportException` when `$document->doctype !== null` after `loadXML`. I re-ran my probe through `parse()`, and a DTD with a `SYSTEM "file://…"` entity is now rejected in UTF-16LE with a BOM, UTF-16BE with a BOM, UTF-16LE without a BOM, and UTF-32LE. A UTF-16 OPML file without a DTD still parses normally.

TASK-5: PASS
notes: `$tries = 1` and `$timeout = 300`, with the one-line reason (rule 9.2). The constructor takes only the id. `run()` returns if the state is missing or not `running`. Known URLs are subscribed with no fetch. New URLs are fetched in chunks of 25 through `Http::pool` with a 5 s timeout, SimplePie's default UA and default redirects. The state is saved after each chunk, then set to `finished`. Laravel's pool returns `ConnectionException` and error responses as values (it only rethrows `StrayRequestException`), so `instanceof Response && successful()` maps every transport failure and every non-2xx to `unreachable`. `FeedAlreadySubscribedException` maps to `already_subscribed`, and `FeedException` (no items) to `not_a_feed`. `failed()` → `fail()` sets leftover `pending` to `unreachable` and the stage to `finished`. With `$tries = 1`, a worker timeout also reaches `failed()`. There are no `Log::` calls.

TASK-6: PASS
notes: Routes are in a `Route::prefix('import')` sub-group before `subscriptions`, with `throttle:feeds-import` only on the upload. The limiter (20/h per user id) sits next to `account-deletion`. Each controller action is one service call wrapped in `ApiResponse::success`, and the typed exceptions are mapped once in `respond()` (rules 1.1, 7.2, 7.3). Messages are sentences and echo no input (7.4). The FormRequest rules match the brief. OpenAPI uses relative paths and unique operationIds, has a multipart body for the upload and covers 401/404/409/422/429 where they apply. The `oneOf` 422 deviation is documented and reasonable.

TASK-7: PASS
notes: Every item on the brief's test list is present: parser shapes and content (ISO-8859-1, BOM, `&`, entity titles), filtering including exactly-255 vs 256, upload 422s/409/429/already_subscribed, the XXE fixture (422 and state 404), select drop/422/404/409, tenant isolation, every job outcome including timeout/404/500/HTML, no fetch for a known feed, `SyncFeed` only for new subscriptions, chunked progress, and `failed()`. Every fetch is faked. Re-review: the new test "rejects a UTF-16 file with a DTD that the byte check cannot see" (`FeedImportApiTest.php:79-91`) expects 422 and a 404 on `state` afterwards. That proves nothing was stored.

TASK-8: PASS
notes: I ran both myself after the fix. `composer analyse`: exit 0, "No errors". `composer test`: the first full run failed 1 test, `TimeTrackingTest` "creates a short entry" ("60 is less than 60"). That is a flake already on `main`, unrelated to this job. `TimeTrackingEntryFactory::short()` uses `numberBetween(15, 60)` (60 included), but the test asserts `< 60`, so it fails about 1 run in 46. TimeTracking is untouched in the working tree. The second full run exited 0 with 1527 tests and 5436 assertions. The only warnings are the expected sandbox `.env` warnings.

Checks: `composer test` exit 0 (full suite, second run; the first run hit the unrelated TimeTracking flake described under TASK-8). `composer analyse` exit 0. No duplicate `operationId`s. No `Log::`, `Cache::` or broad `catch` in the new code.

## Security

- XML: `LIBXML_NONET` is set, and `NOENT`/`DTDLOAD`/`PARSEHUGE` are not. In the probe, external and internal entities were not expanded even when a DTD got through. A DTD in any encoding is now rejected: the byte pre-check runs first, then `doctype !== null` after loading. I verified this for UTF-8, UTF-16LE/BE and UTF-32LE.
- Fetch scope: `select` can only fetch URLs from the user's own parsed state. The state key is per user. User B gets 404 on `state`/`select` (tested).
- SSRF guard: out of scope by owner decision. Not added, as instructed.

## Overall

APPROVED

No blockers. The one blocker from the first pass (a UTF-16/UTF-32 DTD getting past the byte check) is fixed and covered by a test.

Not a blocker, outside this job: the flaky `TimeTrackingTest` "creates a short entry" (`short()` can return 60, but the test asserts `< 60`) needs its own small fix.
