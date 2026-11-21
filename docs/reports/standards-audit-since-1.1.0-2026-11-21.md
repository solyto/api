# Report: standards audit of the API since 1.1.0

date: 2026-11-21
branch: `main`, tree at `872089c`, compared with tag `1.1.0` (215 commits, 657 files changed)
scope: All of `app/`, `routes/`, `config/`, `bootstrap/`, `database/` and `tests/` against `docs/STANDARDS.md` (including Decisions), `docs/policies/*.md` and `docs/TESTING.md`. The frontend standards were not applied. Compatibility claims were checked against the frontend repo at tags 1.0.15 and 1.1.0–1.1.4. The test suite and PHPStan were run. No code was changed.
read: false

Tags used below:
- **NEW**: the line was added or changed after 1.1.0 (`git blame 1.1.0..HEAD`).
- **OLD**: it already existed at 1.1.0.
- **OLD/touched**: an old line in a file or method that was edited since 1.1.0 without fixing it.

## Summary

- **No tenant-isolation gap.**
  - Every action that binds a user-owned model checks ownership.
  - Every new list query starts with `forUser`.
  - Every new `exists:` rule is scoped to the user.
  - The 6 bare `exists:` rules are OLD, listed in §3.2, and each is re-checked elsewhere.
- **OpenAPI matches the routes exactly** (checked by script): 301 operations, 0 without a route, 0 duplicate or missing operationIds, 0 `/api`/`/v1` prefixes, 0 hand-written envelopes.
- **The main bug class is stale caches (§8.2).** A write in one service doesn't forget a key that another service cached with the relation embedded. One case is NEW (budgets embed tags); the others are OLD.
- **The largest architectural deviation is the unreleased MCP server.** It is a new top-level `app/Mcp`, with its own route file and token auth. Its 11 tools call module services directly instead of going through `IntegrationGateway`, and they copy FormRequest rules by hand.
- **Docblocks (§10.2) slipped clearly.** Class-level summaries went from 0 to 10, and prose method docblocks from 95 to 174.
- **Tests are a strength.** New features ship with thorough tests, including other-user (403) and validation (422) cases.
- **PHPStan level 5 is clean** with an empty baseline (about 3600 lines at 1.1.0).

## Test and analysis run

- `make check`: 1807 tests passed and 41 failed. All 41 failures are in `app/Mcp/Tests/McpToolsTest.php` and `McpEndpointTest.php`, with `Class "Laravel\Mcp\Server\McpServiceProvider" not found`. `laravel/mcp` is in `composer.json`/`composer.lock` but missing from the local `vendor/`. This is an environment problem; `composer install` should fix it.
- The 1807 warnings are all `file_get_contents(.env)`; this checkout has no `.env`.
- `make check` stops before PHPStan, so it was run separately. All 74 errors are `class.notFound`/`attribute.notFound` for `Laravel\Mcp\*`, from the same missing package. Nothing else is reported.

## Verdicts per rule

| Rule | Verdict | New / old |
|---|---|---|
| §1.1 Controller action shape | Minor deviations | 4 / many |
| §1.2 Foreign ids via user scope | Adheres | 0 / 0 |
| §1.3 Identical branches | Adheres (fixed) | 0 / 0 |
| §1.4 Routes | Minor deviations | 1 (`routes/ai.php`) / 3 missing `show` + ordering |
| §2 OpenAPI | Adheres, 1 NEW slip | 1 (§2.4) / 0 |
| §3.1 FormRequests, no inline validation | Adheres for new code | 0 / 20 (the 20 STANDARDS records) |
| §3.2 `exists:` is not ownership | Adheres | 0 / 6 known, all neutralised |
| §3.3 / 3.4 `authorize()`, requests don't act | Minor (OLD only) | 0 / 3 |
| §4 Services | Minor deviations | 2 / ~9 |
| §5 Models and queries | Adheres | 0 (McpToken has no `scopeForUser`, no risk) |
| §6 Resources | Minor, 1 OLD bug | 0 / 2 |
| §7 Errors and responses | Adheres | 0 |
| §8.1 Per-user cache via `UserCacheService` | Adheres | – |
| §8.2 Every write forgets every key | **Significant deviations** | 2 / 5 |
| §9 Jobs, commands, schedule | Adheres, 1 minor | 1 / 0 |
| §10.1 Comments say why | Minor deviations | ~10 noise lines |
| §10.2 Docblock whitelist | **Significant deviations** | 10 class blocks + ~79 prose blocks |
| §11 Formatting | Adheres | 1 trivial |
| §12.1 / 12.2 Module layout, one route file, Sanctum | Deviation (MCP, unreleased) | 1 |
| §12.5 / 12.6 Secrets, `env()`, `pgsql` | Adheres | 0 |
| §12.8 Cross-module calls via `app/Shared` | **Significant deviation** (MCP) + 1 minor | 12 / 0 |
| §12.9 Tests | Adheres overall, 3 minor | 3 |
| §12.10 Localisation | Adheres | 0 |
| §13.1 / 13.3 Old frontends | 2 owner-accepted breaks, not recorded in STANDARDS | 2 |
| §13.2 / 13.4 Remove/rename/retype in steps | Adheres | 0 |
| §13.6 Contract tests | Moderate gap | ~6 fields |

## 1. Bugs

1. **Three routes point at a missing `show()`** (OLD). `routes/api.php:314` (`LibraryLinkCategoryController`), `:455` (`TimeTrackingCategoryController`) and `:463` (`TimeTrackingEntryController`) use `apiResource`, which registers `show`. None of the three controllers has a `show()` method. A GET by id hits a missing method and ends in the generic 500. Fix: `->except(['show'])`.
2. **Budgets show stale tags for up to 24 h** (NEW, `442dff6`). `BudgetService::list` (`BudgetService.php:22-29`) caches `budgets_v2` with `->with('tags')`. `TagService::update/destroy` (`TagService.php:42-58`) forget only `tags`, so a renamed or deleted tag stays on budgets until the next budget write or 24 h. Notes have the same gap (OLD, `notes_v2`, `notes_hidden_v2`, `notes_newest_v2` and `notes_recent` embed tags).
3. **Movie genres rendered with the movie resource** (OLD). `Libraries/Resources/LibraryMovieResource.php:45` uses `LibraryMovieResource::collection($this->genres)` instead of `LibraryMovieGenreResource`. Each genre comes back padded with null movie fields (rating, cover, link, …).

## 2. Stale caches (§8.2)

Besides bug 2 above:

| Write | Stale key | Max staleness | Tag |
|---|---|---|---|
| `TodoCategoryService.php:47,57` rename/delete | `todo_workspaces` (embeds categories) | 24 h | OLD (reference module) |
| `TimeTrackingService.php:163-179` category rename/delete | `tt_projects_v2` | 24 h | OLD/touched (key was renamed to `_v2` here, gap left) |
| `Clipboard/Jobs/DeleteOverdueClipboardEntries.php:21-32` | `clipboard_{userId}` for every affected user | 24 h | OLD/touched (now also deletes the new file entries) |
| `CalendarService.php:101-105` `update` (recolour) | `calendar_events_*` (carry `calendar_name`/`calendar_color`) | up to 7 days for past months | OLD |
| `UserService::destroy` | `external_calendar_state` (TTL 0, never expires; `AppCalendarsPDO.php:1358,1383`), `external_calendar_unavailable`, `external_calendar_discovery` | forever | NEW, data hygiene |

Ordering, low risk:
- `ClipboardService::storeFile:76-78` (NEW, copied from OLD `store`/`storeImage`) forgets before writing.
- `IntegrationGateway::startTimeTracking:190-202` (NEW) forgets `tt_projects_v2` inside a transaction that hasn't committed yet.

In both cases a concurrent read can re-cache old data for the full TTL. `BudgetService` does it correctly, forgetting after the transaction.

Possibly by design, for the owner: `book_releases` TTL went from 1 to 7 days and isn't forgotten when books change. It is refreshed by the nightly `GrabBookReleases` job.

Done well:
- Shared-calendar invalidation (the new `InvalidateSharedCalendarCache` job, unique per calendar).
- `forgettingStaleEvents` on a 412.
- `NoteCategoryService::forgetUser` also forgets note lists.
- `DeleteOldFeedItems`.
- Versioned `_v2` keys kept in step with `UserService::USER_CACHE_KEYS`.

## 3. MCP server (§12.1, §12.2, §12.8, §1.4) — NEW, unreleased

Added after 1.1.4 in `8a7c82a`, `e83684d` and `872089c`.

- **§12.8:** all 11 tools in `app/Mcp/Tools/` import module services directly. Examples: `CreateTodo.php:6`, `UpdateTodo.php:7` and `ListTodos.php:7` → `TodoService`; `ListUpcomingEvents.php:6` → `CalendarService`; `SearchNotes.php:5` and `GetNote.php:7` → `NoteService`; `SearchContacts.php:6`; `ListTodoTagsAndCategories.php:6,8`; three dev-request tools. §12.8 says a new external entry point adds gateway methods. The Widgets feature did exactly that (11 new `IntegrationGateway` methods), so there is an in-repo template.
- **Rules copied by hand:** `CreateTodo`, `UpdateTodo` and `CreateDevRequest` copy FormRequest rules inline. They are kept in sync only by a comment ("Same rules as StoreTodoRequest…").
- **§1.4 / §12.2:** new route file `routes/ai.php`, and its own `mcp_tokens` table plus `AuthenticateMcpToken` middleware instead of Sanctum. This is the package's convention and is gated by `config('mcp.enabled')`, but STANDARDS has no Decision for it.
- **§12.1:** `app/Mcp/` is a top-level folder the standard doesn't list. `docs/TESTING.md` doesn't list the new `Mcp` suite (`phpunit.xml:18-20`).
- **Tenant isolation is fine:** tools re-scope with `forUser`, and tag/category ids use `Rule::exists(...)->where('user_id', …)`.

Since it is unreleased, this is the cheapest point to either move it behind the gateway or record Decisions for it.

Separately, NEW and released: `Users/Commands/SendDailyDayRemindersCommand.php:5,51` imports `Calendars\Services\CalendarService` and locates it with `app()` in a loop (§12.8, §4.2). Also `WidgetService.php:6` imports `CheckIn\Resources\CheckInSettingsResource` for its default constants (minor).

## 4. Docblocks and comments (§10)

- **§10.2, all NEW:**
  - Class-level summary blocks went from 0 to 10: `PulseMetrics.php:7`, `FeedReader.php:27`, `ExternalCalendarService.php:22`, `OutboundUrlGuard.php:8`, `InvalidateSharedCalendarCache.php:14`, `McpServiceProvider.php:8`, `UnresolvableHostException.php:5`, `RecordRateLimitedRequests.php:11`, `PulseMetricsServiceProvider.php:24`, `MetricCard.php:11`.
  - Prose method docblocks went from 95 to 174. Worst files: `PulseMetrics.php` (17), `CalendarService.php` (10), `FeedReader.php` (8), `NoteService.php`, `FeedService.php` and `ExternalCalendarService.php` (7 each), `UserService.php` (6).
  - New prose `@param`/`@return`: `PulseMetrics.php:73`, `OutboundUrlGuard.php:42`, `FeedService.php:140`.
  - The content is mostly *why*-reasoning, so the fix is to move what's worth keeping into `//` comments or cut it.
- **§10.1:** `//` lines in `app/Api` went from 65 to 137, but nearly all explain *why*. Noise, all NEW:
  - Plan labels: `PulseMetrics.php:89-177` (`/** A: … */` to `/** F: … */`) and `PulseMetricsServiceProvider.php` (`// H1: …`).
  - Restating the code: `AppServiceProvider.php` "Every new dev request pushes an ntfy alert".
  - Empty `//` stubs in four new `Feeds/Exceptions/*` classes.

## 5. Controllers, routes, OpenAPI, requests (§1–§3)

- **§1.1, NEW:**
  - `Notes/Controllers/NoteController.php:46,75,103,131` return what the service gives back with no `new XResource`. `NoteService.php:217` builds `NoteResource` itself (`json_decode(json_encode(NoteResource::collection(...)))`), the only service that does. The motive, caching arrays instead of models, is reasonable; the shape isn't.
  - `Users/Controllers/UserController.php:160-166` has a super-admin rule and a `match ($status)` branch in the controller.
  - `Export/Controllers/ExportController.php:35` adds a Retry-After calculation to a controller that already queries (OLD `:29`).
  - `Feeds/Controllers/FeedImportController.php:154-165` uses a private closure wrapper, a pattern nothing else uses. Borderline.
- **§1.4, OLD:** in the plants group, `routes/api.php:347-350` puts the custom cover line after `apiResource`.
- **§2.4, NEW:** `Widgets/Controllers/WidgetController.php:297` documents a validation-only 422 with `ErrorResponse`; it should be `ValidationErrorResponse`.
- **§2.6:** the request-body annotations match `rules()` for all 13 new or changed endpoints checked.
- **§3, OLD only:**
  - `UserPolicy` plus `$this->authorize` (`UserController.php:57,104`) is a second authorisation path, against the "no policies" Decision.
  - `ChangePasswordRequest.php:27` (`withValidator`) and `UpdateUserRequest.php:34` (`prepareForValidation`) have behaviour STANDARDS says only `LoginRequest` has.
- **Clean:**
  - 0 `authorize()` in 124 requests.
  - 0 loops in controllers.
  - 0 `env()` in `app/`/`routes/`.
  - 0 new inline validation in `app/Api`.
  - The new controllers (ExternalCalendar, Widget, Clipboard file actions) match the reference shape.

## 6. Services, models, resources, errors (§4–§7)

- **§4.1, OLD:** `Export/Services/ExportService.php:31` takes `User` in its constructor. The rule's grep misses it because the constructor spans several lines.
- **§4.4, NEW:**
  - `CalendarService.php:361-393`: the same "forget events by prefix + `invalidateSharedCalendar`" block in four event methods, with variants in `createEvent`/`moveEvent`. It should be one `forgetEvents()`.
  - `WidgetService.php:35-36,103-104,150-151`: the timezone/locale pair repeated three times.
- **§4.5, OLD:** seven pass-through `find($x) { return $x; }` methods, including `TodoCategoryService.php:26` in the reference module.
- **§4.2, NEW, outside module services:** `Dav/Services/ImportService.php:18` locates `RemoteCalDavClient` in a constructor and could inject it.
- **§4.8:** every new broad catch either rethrows after cleanup, logs with a comment, or isolates one item. `CacheInvalidationPlugin` gained two more `catch (\Throwable)` without a why-comment (the reason is obvious).
- **§6.1, OLD:** `DevRequestResource.php:47-52` reads `votes` without `whenLoaded`. It isn't an N+1 today because the service always loads them.
- **Clean:**
  - §5.5: no new raw SQL in services, and `pgsql` only in the three permitted files.
  - §7.1: only `ApiResponse` builds JSON.
  - §7.2: 0 `catch (\Exception)` in controllers, and new exceptions are in the central map.
  - §4.9: `Log::info` down from 5 to 4.
  - The fixed reference cases: `call_user_func` dispatch, `findGenre` pass-throughs, two of the three `whenLoaded` violations, and the calendar "already exists" pre-check moved into the service.

## 7. Jobs, formatting, architecture, tests (§9, §11, §12)

- **§9.3, NEW:** `routes/console.php:52-57` schedules a closure with logic (`PulseMetrics::queueSize`) instead of a job or command.
- **§9.1 / 9.2:** jobs carry ids. `ImportFeedSubscriptions` sets `tries`/`timeout` with a reason, exactly the shape asked for.
- **§11:** follows each file's style. One trivial slip: `AuthService.php:94` `! $parent` in a `!$x` file. `CheckInSettingsResource::DEFAULT_TRACKERS`/`DEFAULT_SPORTS` were made public but left untyped.
- **§12.5 / 12.6:**
  - No `env()` outside config (besides the allowed `bootstrap/app.php:33`).
  - External calendar passwords use the `encrypted` cast and are `$hidden`.
  - No migration was edited after any release from 1.1.1 to 1.1.4.
  - Destructive statements appear only in `down()`.
- **§12.9 / TESTING.md:**
  - `tests/Feature/PulseMetricsTest.php` (NEW) breaks "keep `tests/Feature` empty".
  - New model/factory-style tests that TESTING.md says not to write: `Calendars/Tests/ExternalCalendarModelTest.php`, and two factory-state tests in `DevRequests/Tests/DevRequestTest.php`.
  - `RecordRateLimitedRequests` has no direct test.
- **§12.10:** `config('app.locales')` is used everywhere, and all 6 locales have identical keys.

## 8. Compatibility with older frontends (§13)

- **No unsafe removal, rename or retype.** Each was checked against 1.0.15:
  - `keywords` → `whitelist`, `FeedResource.description` and the vapid-key `{public_key}` change all fixed or removed fields that were always null or already broken.
  - The new dev-request status is sent as `in-progress` to clients below 1.1.3.
  - Clipboard files are opt-in (`include_files`).
- **Two deliberate breaks, NEW, signed off in job docs but not recorded as exceptions in STANDARDS:**
  1. Clipboard images moved to a private disk (`2026_11_16_120001_move_clipboard_images_to_private_disk.php`). Builds 1.0.0–1.1.3 show broken thumbnails. Sign-off: `docs/jobs/archive/maintain_pdf-clipboards/verdict.md:37`.
  2. Quarterly and yearly budgets appear in the plain list, and 1.0.15 sums every value as monthly (`Finances.svelte.ts:69-79`), so totals are wrong. The job's `tasks.md:19-21` says "Rule 13.3 … is knowingly overridden".
- **Not examined by anyone:** external calendars (`is_external`/`is_unavailable`) appear in `GET calendars` for old builds. No job doc records what 1.0.15 does with them, e.g. when renaming or deleting one.
- **§13.6 contract-test gaps.** Fields released builds read without a test in `ClientContractTest.php`:

  | Field | First build that reads it |
  |---|---|
  | `meta.account_deletion_requires_email` | 1.1.1 |
  | `week_start` | 1.1.2 |
  | `fixed_in_version` | 1.1.2 |
  | `is_external` / `is_unavailable` / `is_credentials_rejected` | 1.1.3 |
  | `meta.unavailable_calendars` | 1.1.3 |

  `data.public_key` (vapid key) and `whitelist`, read by 1.0.15, are covered only by module tests. The "Since 1.0.15" block at `ClientContractTest.php:352-378` now also asserts `has_photo`, which first appears in 1.1.4.

## 9. Documentation drift

- STANDARDS §2.2 still says the `searchAlbumOnDeezer` drift stays on purpose. It was removed in `2afca11`, and `OPENAPI_KNOWN_DRIFT` is empty.
- STANDARDS §4.1 says every other service takes the user as an argument; `ExportService` doesn't.
- STANDARDS §3.4 says `LoginRequest` is the only request with behaviour; it isn't.
- `docs/TESTING.md` doesn't list the `Mcp` suite.

## 10. Suggested order of work

1. Fix the three missing-`show` routes, the movie genre resource and the budget/tag cache gap.
2. Decide the MCP architecture before release: move the tools behind `IntegrationGateway`, or record Decisions for `app/Mcp`, `routes/ai.php` and the token auth.
3. Close the remaining §8.2 gaps (todo workspaces, time-tracking projects, clipboard cleanup job, calendar recolour, user-deletion keys).
4. Add contract tests for the §13.6 fields, and record the two accepted breaks in STANDARDS.
5. Trim the §10.2 docblocks and the plan-label comments.
6. Update STANDARDS §2.2, §3.4, §4.1 and TESTING.md.

## 11. Follow-up (2026-11-21, same day)

Worked through with the owner after the audit.

**Fixed:**
- §1 bugs 1 and 3: `->except(['show'])` on the three routes (a GET by id is now 405, not 500); movie genres through `LibraryMovieGenreResource`. Regression tests in `LibraryRestApiTest` and `TimeTrackingApiTest`.
- §2 caches: a todo category change now forgets `todo_workspaces`, a time-tracking category change forgets `tt_projects_v2`, a calendar recolour forgets that calendar's cached events (the app draws event colours from `calendar_color` and reloads events right after a recolour). One test each, red without the fix.
- §8 contract tests: 28 new tests in `tests/Unit/ClientContractTest.php` for what builds 1.0.15–1.1.4 read, plus `tests/Fixtures/client-contract/1.1-routes.json` (37 routes first called by 1.1.0–1.1.4) and a test that they still exist. Nothing was broken.
- §8: the two accepted breaks are recorded under STANDARDS Decisions (both affect builds up to 1.1.3).
- §9: STANDARDS §2.2, §3.4, §4.1 and `docs/TESTING.md` (Mcp suite, Pest bindings) brought in line with the code.

**Corrections to this report:**
- §1 bug 2 is wider than reported: tags are also embedded in the cached notes lists and in `books`, `games`, `movies`, `links`, `quotes`. Set aside by the owner; see `docs/backlog/cache-followups.md`.
- §2 table, last row: wrong. Account deletion runs through `AppCalendarsPDO::deleteCalendar`, which forgets `external_calendar_state` for every linked calendar; the other two keys expire within minutes. Nothing stays forever.
- §8 contract gaps were incomplete: 1.1.3 never reads `is_unavailable` or `is_credentials_rejected`, it reads `meta.credentials_rejected_calendars`; 1.0.15 also reads `feed_id`; and the 1.1.x builds call 37 new routes whose fields were not pinned at all. All covered now.
- §3: the owner decided not to move MCP behind `IntegrationGateway`. Instead the gateway is to be dissolved: entry points (bot, MCP, widgets) call module services directly, like controllers.

**Not done, recorded in the backlog:** `dissolve-integration-gateway.md`, `bot-day-timezone.md`, `mcp-standards-decisions.md`, `cache-followups.md`, `widget-client-contract-tests.md`, `standards-audit-followups.md`, `bug-review-since-1.1.0.md`.
