# Implementation: create tests

id: delay
status: done
developer: opencode-go/deepseek-v4-flash
date: 2026-08-13

## Summary

Built a complete test suite for the Solyto API from a state where the pre-existing
factory/model tests could not even run (the whole suite failed on a hard-coded
`pgsql` connection and a `mysql`-bound webpush migration). The suite grew from
286 failing tests to **618 passing tests (1455 assertions)**.

All 34 tasks were implemented:

- TASK-1 — test scaffolding (`tests/Unit`, `tests/Feature` with their own
  `Pest.php`, shared API-auth helpers, phpunit coverage configuration).
- TASK-2 — the hard-coded `pgsql` connection (DAV stack, StatisticsService) now
  resolves to a **separate file-backed SQLite database** in tests. A single
  shared in-memory DB was infeasible because the app's native `calendars`/`users`
  tables collide with Sabre's DAV tables; the separate DAV file is reset once per
  process and wrapped in a per-test transaction via `connectionsToTransact`.
  The DAV migration was made driver-aware (SQLite DDL emitted for non-pgsql).
- TASK-3..TASK-33 — unit/feature/service/job/command tests for every module
  (ApiResponse, shared helpers/enums, DAV helpers, auth, users, friends,
  passkeys, todos, notes, tags/shortcuts, check-in, clipboard, time tracking,
  finances, libraries incl. external providers, AI service, calendars, contacts,
  feeds, weather, statistics, quick-add, dev requests, notifications/telegram,
  export, bots, DAV services, queue jobs, artisan commands, OpenAPI-vs-routes).
- TASK-34 — full suite green and stable across repeated runs (4 consecutive
  clean runs), flaky boundary conditions fixed.

## Changes

TASK-1: `tests/Unit/Pest.php`, `tests/Feature/Pest.php`, `phpunit.xml` — test
  directories with their own Pest bindings, shared helpers
  (`makeUser`, `sanctumToken`, `authHeaders`, ...), coverage config
  (`<coverage cacheDirectory=".phpunit.cache"/>`).

TASK-2: `tests/TestCase.php` — pgsql→sqlite file-backed DAV database,
  webpush/telescope/pulse connections to sqlite, `user_data`/`conversation_state`
  cache stores to array, no-op `ImageTransformationService` double.
  `database/migrations/2025_12_08_161442_create_dav_tables.php` — SQLite-portable
  schema branch. `2025_12_11_215552_add_color_to_addressbooks.php` and
  `2026_02_16_120000_add_boardgame_platform_to_library_games.php` — driver-aware.
  Fixed pre-existing factory/schema mismatches that blocked the whole suite
  (UserFactory model binding, Todo/Note/etc. factories, broken test expectations).

TASK-3: `tests/Unit/ApiResponseTest.php` — envelope, resources, pagination meta.

TASK-4: `tests/Unit/UrlHelperTest.php`, `DockerSecretHelperTest.php`,
  `EnumsTest.php`, `DetectionResultTest.php`, `KeyboardTest.php`.

TASK-5: `tests/Unit/Dav/*` — DavHelper, Dav UrlHelper (fixed `getBaseUrl`
  undefined-index edge case), ICalendarHelper, VCardHelper XML/ICS/VCard
  parsing. Dead code referencing removed `App\Models\*` was skipped.

TASK-6: `app/Api/Users/Tests/AuthTest.php` — register/login/logout/refresh/
  verify/forgot/reset/tokens/revoke + AuthService unit tests. Fixed
  `AuthController::revokeToken` reading a non-existent route parameter.

TASK-7: `UserApiTest.php` — profile, users, change-password, profile image,
  all me/settings sub-routes. Fixed: missing `UserController::changePassword`,
  navigation JSON decode, `ai_enabled` null handling, settings boolean casts.

TASK-8: `FriendApiTest.php` — friends/requests flow + FriendService. Fixed
  `BaseNotification::via()` not reading DB defaults after `firstOrCreate`
  (notification channels were never resolved on first use).

TASK-9: `PasskeyTest.php` — full WebAuthn register/authenticate with hand-built
  CBOR fixtures (attestation objects, COSE EC keys, real signatures). Fixed
  `PasskeyController` passing the wrong request slice to the service.

TASK-10: `TodoApiTest.php` — categories/workspaces/todos/subtasks + parse
  matrix. Fixed `TodoService::listDueDate` querying non-existent `due_date`
  column (silently broken on SQLite, fatal on MySQL) and update-workspace
  request missing `title`.

TASK-11: `NoteApiTest.php` — notes/categories CRUD, newest, md+zip import,
  NoteService. Fixed nullable-title factory.

TASK-12: `TagApiTest.php`, `ShortcutApiTest.php` — CRUD + reorder. Fixed
  `UpdateTagRequest` validating `title` instead of `name`.

TASK-13: `CheckInApiTest.php` — index/store, per-date upsert, validation.

TASK-14: `ClipboardApiTest.php` — text/image flows, image serve/delete with
  `Storage::fake`. Fixed `ClipboardService::getImagePath(int $userId)` type hint.

TASK-15: `TimeTrackingApiTest.php` — categories/projects/entries, start/stop
  state transitions (409s), statistics aggregation.

TASK-16: `FinanceApiTest.php` — budget CRUD, wealth fields/values. Fixed
  `WealthController::updateValue` discarding the created value.

TASK-17: `LibraryApiTest.php` — book/music CRUD + genres, Hardcover/Deezer
  fakes, search endpoints.

TASK-18: `LibraryRestApiTest.php` — games/movies/links/quotes/recipes/plants/
  covers + genre controllers, Steam/Imdb/Tmdb/Bgg/Chefkoch fakes. Fixed plant
  factory columns not matching the schema.

TASK-19: `LibraryServiceTest.php` — AiService (OpenAI client made injectable via
  `ClientContract`), LibraryRecommender, LibraryReleases.

TASK-20: `CalendarApiTest.php` — calendar/event CRUD, attachments. Fixed:
  UUID-string event ids typed as `int` in controllers/services/scopes,
  `EventDTO` VTIMEZONE crash for transition-less timezones (UTC),
  `forgetByPrefix` not catching `Error`.

TASK-21: `ContactApiTest.php` — DAV-backed address books/contacts/photos.

TASK-22: `FeedApiTest.php` — subscription CRUD, sync dispatch (mocked
  FeedReader), items/available/search/friends/test.

TASK-23: `WeatherTest.php` — today endpoint + WeatherService caching with
  Http::fake.

TASK-24: `StatisticsTest.php` — admin-only overview + DAV counts.

TASK-25: `QuickAddTest.php` — detection matrix + commit paths.

TASK-26: `DevRequestApiTest.php` — CRUD, one-vote-per-user, comments.

TASK-27: `NotificationApiTest.php` — notifications CRUD, settings, push
  subscribe/unsubscribe, telegram token/request/alerts. Fixed
  `TelegramBotConnectionController::getRequest` crashing on missing connection.

TASK-28: `ExportTest.php` — export store/status/download, ProcessExport
  success/failure, TodoExportService CSV, DeleteExpiredExports.

TASK-29: `app/Bots/Tests/SolytoBotTest.php` — ConversationState, connect,
  quick-add auto-commit, day/todos commands with Http::fake + mocked gateway.

TASK-30: `app/Dav/Tests/DavServiceTest.php` — calendars/events/address books/
  contacts/principals/sharing against the shared DAV sqlite, VCardPhotoProcessor,
  DavServerFactory.

TASK-31: `app/Api/Feeds/Tests/JobTest.php`, `app/Api/Users/Tests/JobTest.php` —
  SyncFeed/SyncFeeds/DeleteOldFeedItems/DeleteOldDevRequests/
  DeleteOverdueClipboardEntries/DeleteOldFriendRequests/ScaleProfileImage/
  GenerateCoverPreview/ScaleCovers.

TASK-32: `CommandTest.php` — user list/search/create-principals/reset-dav,
  test-notification, telegram bot commands, daily reminders. Fixed
  `ResetDavForUser` (broken `new DavService()`, hard-coded email, wrong
  delete signatures).

TASK-33: `tests/Unit/OpenApiSpecTest.php` — parses the l5-swagger annotations
  and asserts every documented operation resolves to a registered route with a
  matching verb. The annotations predate the current routing; the 80 stale
  operations are pinned in `OPENAPI_KNOWN_DRIFT` so new drift fails.

TASK-34: fixed flakes (todo `completed_at` when factory pre-completed, recipe
  quick/time-consuming boundary values, clipboard file-extension regex) and ran
  the full suite repeatedly (4× green).

## Known issues / follow-ups

- **No code-coverage driver installed** in this environment (neither xdebug nor
  pcov), so a real coverage report could not be produced. The phpunit coverage
  configuration (`<coverage cacheDirectory>`, `<source>`) is in place; run
  `XDEBUG_MODE=coverage vendor/bin/pest --coverage-text` on a machine with a
  driver to get percentages. Coverage is therefore reported as
  "suite green (618 tests)" rather than a percentage.
- **OpenAPI annotations are stale**: 80 documented operations don't match the
  current route table (legacy paths/verbs, e.g. `api/check-ins` vs
  `api/v1/check-in`, `api/feeds` vs `api/v1/feeds/subscriptions`,
  `api/telegram/token` vs `api/v1/telegram/token-request`). They are pinned as
  known drift in the OpenAPI test; updating the annotations themselves is a
  separate documentation task.
- Dead code referencing removed models (`App\Models\NextcloudContact`,
  `App\Models\NextcloudCalendarEntry`) in `VCardHelper`/`ICalendarHelper` was
  left untouched (only the live parsing methods are tested).
- `GoodreadsService` (raw `file_get_contents`) and `DiscogsService`
  (third-party client) could not be unit-tested without HTTP seams; their DTO
  parsing is covered indirectly via the shared DTO tests.
- Image manipulation (Imagick/imgproxy) is stubbed out in tests via a no-op
  `ImageTransformationService` double; image-processing jobs are asserted by
  dispatch/result rather than by real pixel work.
- `UserCacheService::forgetByPrefix` is Redis-specific and now safely no-ops on
  non-Redis stores (catch widened to `\Throwable`).
