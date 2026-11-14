# Implementation: import feeds from an OPML file (api)

id: harvest
developer: developer (run inline in the owner's session)
date: 2026-11-13

## Summary

Three endpoints under `feeds/import` (upload, select, state), an
`OpmlImportService` that parses the file and runs the import, and a queued
`ImportFeedSubscriptions` job that fetches new feeds 25 at a time with
`Http::pool`. State lives in `UserCacheService` for 60 minutes and follows
the brief's contract exactly. `createSubscription` keeps its signature and
behaviour; it now shares one private `subscribe()` with the import.
`composer test` (1526 tests, 46 new) and `composer analyse` both exit 0.

## Changes

TASK-1: `FeedReader::parseFeedData(string $content)` parses downloaded content with a fresh `SimplePie` and `set_raw_data`, returning the `getFeedData` shape. `getFeedData`/`getItems` are untouched. Tests: parses the fixture, `false` for HTML and an empty feed, and parsed content does not leak into a later `getFeedData` on the same reader.
TASK-2: `FeedService::createSubscription` is now a one-line call to a private `subscribe()` that holds the old body unchanged, with the fetch passed in as a closure (still only called when the `Feed` row is missing). `createImportedSubscription(User, title, url, array|false|null $feedData)` uses the same method with already-fetched data (`null` = feed exists, nothing fetched). `plainTitle` is public, so the OPML parser reuses it (docblock says why).
TASK-3: Exceptions `FeedImportException` (422), `FeedImportConflictException` (409), `FeedImportNotFoundException` (404). Enums `FeedImportStage`, `FeedImportStatus` in `Feeds/Enums/`. `FeedImportStateDTO` (`stage`, `feeds`, `total()`, `processed()`). `FeedImportStateResource` with schema `FeedImportState` and `@mixin`.
TASK-4: `OpmlImportService`: `start`, `select`, `state` for the controller; `run`, `fail` for the job. Parser follows brief steps 1-11. `already_subscribed` is one join query. Cache key `feed_import`, TTL 3600, both `private const`. `MAX_FEEDS = 500` is public so `SelectImportFeedsRequest` uses it.
TASK-5: `ImportFeedSubscriptions(string $userId)`, `$tries = 1`, `$timeout = 300` with a one-line reason. It calls `OpmlImportService::run`, which subscribes known feeds without a fetch, then fetches new ones in chunks of 25 (5 s timeout, SimplePie's default User-Agent) and saves the state after each chunk. `failed()` calls `OpmlImportService::fail`: leftover `pending` → `unreachable`, stage `finished`.
TASK-6: `ImportFeedsRequest`, `SelectImportFeedsRequest`, `FeedImportController` (each action one service call, the three exceptions mapped in one private `respond()`), routes in a `feeds/import` sub-group before `subscriptions`, `feeds-import` limiter (20/h per user) on the upload only. OpenAPI: `feedImportStart`/`feedImportSelect`/`feedImportState`, multipart body, `FeedImportState` schema, 401/404/409/422/429 as applicable.
TASK-7: `OpmlImportServiceTest` (shapes, content, filtering, already subscribed), `FeedImportApiTest` (upload, select, state, isolation, rate limit), a new `ImportFeedSubscriptions` block in `JobTest` (every outcome, no fetch for known feeds, User-Agent, chunking and progress, not-running no-op, `failed()`), three `parseFeedData` tests in `FeedReaderTest`.
TASK-8: `composer test` exit 0 (1526 tests; every test prints a `.env` warning in this sandbox because there is no `.env` and `.env.testing` is masked; unrelated to this job). `composer analyse` exit 0. `OpenApiSpecTest` and `ResourceSchemaDriftTest` green, no new drift.

## Deviations from the brief

- **XML loading.** The brief says `simplexml_load_string` with the four
  flags. With `LIBXML_RECOVER`, non-XML input ("this is not xml") returns a
  `SimpleXMLElement` that throws `Error: not properly initialized` on first
  access, which turned an unreadable upload into a 500 (found by the test).
  The parser now uses `DOMDocument::loadXML` with the same flags and
  `simplexml_import_dom`. Same libxml, same flags, same safety; a comment in
  the code says why.
- **One 422 per endpoint in OpenAPI.** A status code can appear once per
  operation, so the FormRequest 422 and the service's own 422 share one
  response with `oneOf` `ValidationErrorResponse` / `ErrorResponse`. The
  existing precedent (`ExternalCalendarController`) puts both under
  `ValidationErrorResponse` only.
- **Enums.** Stage and status are string-backed enums (`Feeds/Enums/`),
  not bare strings. The JSON values are the brief's strings.
- **Job body in the service.** The job is a thin wrapper around
  `OpmlImportService::run`/`fail`, the way `SyncFeed` wraps
  `FeedService::syncFeed`.

## Decisions taken inline

- Three exception classes, one per status (the smallest set that can
  express 422/409/404 without a status field on the exception).
- `user_feeds` is forgotten once per new subscription (the shared
  `subscribe()` already does it).
- A fresh `SimplePie` per parsed body: raw data on the reader's shared
  instance would be served to later `getFeedData` calls (tested).
- `ImportFeedSubscriptions::failed()` resolves the service with `app()`,
  because `failed()` gets no method injection. It is the first `failed()`
  in the codebase.
- Any throwable from the HTTP pool (not only `ConnectionException`) counts
  as `unreachable`: Laravel's pool returns failures as values, so nothing
  escapes `Http::pool` per request.

## Findings / follow-ups

- SimplePie writes `PHP Notice: A feed could not be found at ...` to the
  PHP error log for every non-feed body (`Misc::error` → `error_log`). This
  happens on the existing single-subscribe path too; the import can produce
  up to 500 such lines. Not changed.
- `.env.testing` is a character device in this sandbox, so `git diff`
  in `api/` fails on it (`unsupported file type`). Diff specific paths.
- Out of scope per brief and untouched: SSRF guard, folders, duplicate-feed
  race, `app:feeds:import`, Calendar/Contacts import code.
