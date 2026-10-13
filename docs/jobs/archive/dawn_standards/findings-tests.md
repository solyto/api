# Findings — tests (TASK-7)

Counts from 2026-10-13; `grep -rcE '^\s*(it|test)\(' app tests --include=*Test.php`.

## Inventory

- 59 test files, 720 `it(` blocks. 47 under `app/Api/*/Tests`, 1 each under
  `app/Bots/Tests` and `app/Dav/Tests`, 10 under `tests/Unit`. `tests/Feature/`
  holds only `Pest.php`.
- Binding: `tests/Pest.php:6` binds `Tests\TestCase` for `Feature`, `Unit`,
  `../app/Api`, `../app/Bots/Tests`, `../app/Dav/Tests`; `app/Api/Pest.php`
  adds `RefreshDatabase` for every module test. `tests/Unit/Pest.php` and
  `tests/Feature/Pest.php` bind `TestCase` a second time for the same dirs
  (skeleton residue). 43 module test files still declare
  `uses(RefreshDatabase::class)` themselves — redundant with `app/Api/Pest.php`.
- Root `pest.php` lists the five directories again for Pest 4's own discovery.

## Classification: what the 720 tests actually test

| Kind | Files | `it(` | Example |
| --- | --- | --- | --- |
| Factory/model tests ("creates a valid X", "has correct fillable attributes", "casts attributes correctly", "belongs to a user") | 12 `*Test.php` without `Api` in the name: `LibraryTest` 70, `UserTest` 35, `TodoTest` 25, `NoteTest` 23, `FinanceTest` 23, `FeedTest` 23, `DevRequestTest` 23, `TimeTrackingTest` 19, `CheckInTest` 11, `ClipboardTest` 10, `ShortcutTest` 10, `TagTest` 6 | **278 (39 %)** | `app/Api/Todos/Tests/TodoTest.php` — 18 of 25 blocks assert that a factory state produces the attribute the state sets; "has correct fillable attributes" tests `$fillable` against itself |
| API contract tests (`actingAs` + `/api/v1/...` + envelope assertions) | 17 `*ApiTest.php` / `LibraryRestApiTest` | ~230 | `TodoApiTest.php:13-60` — CRUD round trip + "scopes … to the authenticated user" |
| Service / parsing / job / command tests | `QuickAddTest`, `LibraryServiceTest`, `GrabReleasesTest`, `FeedReaderTest`, `FeedServiceTest`, `*ServiceTest` (Discogs, Goodreads, Spotify), `JobTest` ×2, `CommandTest` ×2, `ExportTest`, `StatisticsTest`, `TelegramBotConnectionTest`, `SolytoBotTest`, `DavServiceTest` | ~110 | `TodoApiTest` `TodoService::parse` block |
| Unit tests of shared code | `tests/Unit/*` (ApiResponse, DAV helpers, enums, DockerSecretHelper, UrlHelper, Keyboard, DetectionResult, OpenApiSpec) | 69 | `tests/Unit/ApiResponseTest.php` with `covers()` |

Verdict: the factory/model layer tests Laravel (factories, casts, relations)
and should not grow; the API-contract and service layers are the ones worth
keeping and extending. `TESTING.md` says so.

## Tenant isolation coverage

Files asserting a 403 or an empty list for a second user: `TodoApiTest` (1),
`NoteApiTest` (4), `ClipboardApiTest`, `FinanceApiTest`, `ShortcutApiTest`,
`StatisticsTest`, `TagApiTest` (1 each), `FriendApiTest`, `PasskeyTest`,
`UserApiTest` (2 each). **No 403/isolation assertion** in: `CalendarApiTest`,
`CheckInApiTest`, `ContactApiTest`, `DevRequestApiTest`, `FeedApiTest`,
`LibraryApiTest`, `LibraryRestApiTest`, `TimeTrackingApiTest`,
`NotificationApiTest`, `WeatherTest`, `ExportTest`.
No test anywhere sends another user's `category_id` / `tags` / `genres` /
`parent_id` in a payload (`grep -rn "forUser(\$other)" app/Api/*/Tests` finds
only list/show isolation) — the gap behind the TASK-5 security finding.

## Helpers vs re-rolled auth

- `makeUser()` is used in 29 files (305 calls); `actingAs($user, 'sanctum')`
  in 23 files (314 calls). `authHeaders()` / `sanctumToken()` from
  `tests/Pest.php`: 5 callers, all in `AuthTest.php`. `expectApiSuccess` / `expectApiError`:
  **0 callers** (defined, unused).
- `createToken(` directly: `AuthTest` (10), `UserApiTest` (2) — these test
  token lifecycle itself, so direct use is right there.
- Custom expectations in `tests/Pest.php`: `greaterThan`/`lessThan`/
  `toBeBoolean`/`toBeEmail` used 23 times across 6 factory-test files
  (`UserTest` 10, `TelegramBotConnectionTest` 6, `CheckInTest` 3, `TodoTest` 2,
  `FeedTest` 1, `NoteTest` 1); `toBeOne`: 0. All four alias Pest built-ins
  (`toBeGreaterThan`, `toBeLessThan`, `toBeBool`) except `toBeEmail`.

## Style

- `describe()` per resource, `it()` per behaviour: consistent across all files.
- Comment lines (`// `) in tests: `CalendarApiTest` 15, `GrabReleasesTest` 6,
  `NoteTest` 3, others ≤ 2. The `CalendarApiTest:141-151` comments explain
  *why* a fixture is shaped that way (DTEND boundary) — the kind that stays.
- `covers()` is used in 12 files (`tests/Unit/*` plus `FeedReaderTest`,
  `DiscogsServiceTest`, `GoodreadsServiceTest`).
- One behaviour per `it`: `TodoApiTest.php:14` "lists, stores, shows,
  updates and deletes" packs five calls in one block; the frontend's
  `TESTING.md` would call that a round-trip test and accept it once per
  resource.
- `OpenApiSpecTest.php:16-97` pins 80 stale annotations as `KNOWN_DRIFT`
  rather than fixing them; the test guards against *new* drift only.

## What is good

- `tests/TestCase.php` is a careful, documented bootstrap (per-process DAV
  SQLite file, transaction wrapping of both connections, doubles for
  imgproxy/AI). Written by the AI test job, verified by the owner's
  `62dc51c`.
- Fixtures (`tests/Fixtures/*.rss|atom|html`) instead of mocked parsers.
- External HTTP is faked through the `359c757` seams; no live calls.
