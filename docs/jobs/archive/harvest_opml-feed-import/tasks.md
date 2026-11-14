# Tasks: import feeds from an OPML file (api)

id: harvest
analyst: analyst (run inline in the owner's session)
date: 2026-11-13

## Starting points found during analysis

- `routes/api.php:385-399`: the `feeds` prefix group. It has no import
  routes yet. The new `import` sub-group goes before `subscriptions`.
- `app/Api/Feeds/Services/FeedService.php:83-120`: `createSubscription`
  fetches through `FeedReader::getFeedData` only when the `Feed` row is
  missing, then creates the feed and the subscription, forgets
  `user_feeds` and dispatches `SyncFeed`. `plainTitle` (`:353`) is private
  and calls the private `sanitizeText`.
- `app/Api/Feeds/Services/FeedReader.php`: one `SimplePie` instance per
  reader, reused across calls. `set_raw_data` exists in the installed
  SimplePie (`vendor/simplepie/simplepie/src/SimplePie.php:812`). Its
  default User-Agent is `\SimplePie\Misc::get_default_useragent()`.
- `app/Api/Feeds/Commands/ImportFeeds.php:104`: the `&` escape regex.
- `app/Shared/Services/UserCacheService.php`: `store`/`get`/`replace`/`forget`.
  In tests the `user_data` store is `array` (TESTING.md).
- `app/Shared/Providers/AppServiceProvider.php:81`: `account-deletion`
  limiter, the place for `feeds-import`.
- `app/Api/Calendars/Controllers/ImportController.php` and
  `Calendars/Resources/ImportStateResource.php`: the flow to follow, not
  the code to copy (pre-checks in the controller, `report()` catches).
- Tests: `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so a dispatched job
  runs inline. `SyncFeed` must be faked wherever subscriptions are created.
  Laravel is 12.70: `Http::pool`, `Http::failedConnection()` are available.

## Task breakdown

TASK-1: `FeedReader::parseFeedData(string $content): array|false`, parsing downloaded content through a fresh SimplePie with `set_raw_data`, same return shape as `getFeedData`.
     files: app/Api/Feeds/Services/FeedReader.php, app/Api/Feeds/Tests/FeedReaderTest.php
     depends: none
     risk: low. A fresh instance per call, because the reader's shared instance would keep the raw data and serve it to later `getFeedData` calls.

TASK-2: Split `FeedService::createSubscription` so the import shares one private method (find feed, already-subscribed check, create feed if needed, create subscription, forget, dispatch). Add `createImportedSubscription` taking already-fetched feed data. Make `plainTitle` public for the parser.
     files: app/Api/Feeds/Services/FeedService.php
     depends: TASK-1
     risk: medium. `createSubscription`'s signature, behaviour and exceptions must not change. The existing feed tests are the guard.

TASK-3: Exceptions, state DTO and resource: `FeedImportException` (422), `FeedImportConflictException` (409), `FeedImportNotFoundException` (404) in `Feeds/Exceptions/`; `FeedImportStateDTO` in `Feeds/DTOs/`; `FeedImportStateResource` with `@OA\Schema` `FeedImportState` and `@mixin`.
     files: app/Api/Feeds/Exceptions/*.php (new), app/Api/Feeds/DTOs/FeedImportStateDTO.php (new), app/Api/Feeds/Resources/FeedImportStateResource.php (new)
     depends: none
     risk: low. The DTO must survive the cache (plain properties, no closures).

TASK-4: `OpmlImportService`: parse (steps 1-11 of the brief), `start(User, content)`, `select(User, urls)`, `state(User)`, plus the state read/write the job uses. Key and TTL as `private const`s, cache through `UserCacheService`.
     files: app/Api/Feeds/Services/OpmlImportService.php (new)
     depends: TASK-2, TASK-3
     risk: medium. XML safety (no DTD, `LIBXML_NONET`, no `NOENT`) and the select filter (no URL outside the parsed state may be fetched).

TASK-5: Job `ImportFeedSubscriptions(string $userId)`: existing feeds subscribed directly, new ones fetched 25 at a time through `Http::pool` (5 s, SimplePie UA), state written after each chunk, `finished` at the end; `failed()` marks leftover `pending` as `unreachable` and finishes.
     files: app/Api/Feeds/Jobs/ImportFeedSubscriptions.php (new)
     depends: TASK-1, TASK-2, TASK-4
     risk: medium. `$timeout = 300` below `retry_after` 360. `$tries = 1`.

TASK-6: HTTP layer: `ImportFeedsRequest`, `SelectImportFeedsRequest`, `FeedImportController` (`start`, `select`, `state`, exceptions mapped once), routes in a `feeds/import` sub-group, `feeds-import` limiter (20/h per user) on the upload only. OpenAPI annotations on the three actions.
     files: app/Api/Feeds/Requests/*.php (new), app/Api/Feeds/Controllers/FeedImportController.php (new), routes/api.php, app/Shared/Providers/AppServiceProvider.php
     depends: TASK-4, TASK-5
     risk: low. `OpenApiSpecTest` and `ResourceSchemaDriftTest` must stay green with no new drift.

TASK-7: Tests: parser shapes and content through `app(OpmlImportService::class)`, upload/select/state endpoints, job outcomes with `Http::fake`, `failed()`, tenant isolation, 255-character filter.
     files: app/Api/Feeds/Tests/OpmlImportServiceTest.php, app/Api/Feeds/Tests/FeedImportApiTest.php, app/Api/Feeds/Tests/JobTest.php (new describe block)
     depends: TASK-1 to TASK-6
     risk: low. No live HTTP; `Queue::fake` only where dispatch alone matters.

TASK-8: `composer test` and `composer analyse`, full suite, both exit 0.
     files: none
     depends: all
     risk: low.

## Unclear / open questions

- None blocking. Decisions taken inline and to be listed in the
  implementation notes: exception set (three classes), state as a DTO,
  fresh SimplePie per parsed body, `user_feeds` forgotten once per new
  subscription (the existing behaviour of the shared method).
