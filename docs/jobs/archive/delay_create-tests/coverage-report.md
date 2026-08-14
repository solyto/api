# Coverage report & next steps

id: delay
date: 2026-08-14
author: opencode-go/deepseek-v4-flash

## Current status

- Test suite: **618 tests / 1455 assertions**, green and stable across repeated runs.
- Coverage driver: **not installed** in the development/test environment (neither
  `xdebug` nor `pcov`). No percentage-based coverage report could be produced;
  the phpunit coverage config is already in place (`phpunit.xml`:
  `<coverage cacheDirectory=".phpunit.cache"/>` + `<source><directory>app</directory></source>`).
- OpenAPI/route-drift test (`tests/Unit/OpenApiSpecTest.php`) pins the 80 known
  stale annotations so future drift is caught.

## How to actually measure coverage

On a machine with a coverage driver installed:

```bash
# Option A: pcov (fast, no php.ini restart needed)
pecl install pcov
vendor/bin/pest --coverage --min=80

# Option B: xdebug
XDEBUG_MODE=coverage vendor/bin/pest --coverage-text

# HTML/XML report for CI artifacts
XDEBUG_MODE=coverage vendor/bin/pest --coverage-clover .phpunit.cache/clover.xml
XDEBUG_MODE=coverage vendor/bin/pest --coverage-html .phpunit.cache/coverage
```

`--min=<n>` makes the run fail when line coverage drops below `<n>`; useful as a
CI gate once a realistic threshold is chosen.

## Coverage map (per module)

Legend: `[x]` solid, `[~]` partial, `[ ]` thin / untested.

| Module | State | Coverage notes |
|---|---|---|
| ApiResponse envelope | [x] | `tests/Unit/ApiResponseTest.php` (success/error/shortcuts/resources/pagination meta) |
| Shared helpers & enums | [x] | UrlHelper, DockerSecretHelper, AuthPlatformEnum, AiUsageFeatureEnum, QuickAddContentType, DetectionResult, Keyboard, SolytoMessage |
| DAV helpers | [~] | VCard/ICalendar/Dav/Url helpers covered; dead code referencing removed `App\Models\*` skipped |
| Auth | [x] | register/login/logout/refresh/verify/forgot/reset/tokens/revoke + AuthService edge cases |
| Users / settings | [x] | me, CRUD, public-profile, change-password, profile image, all settings sub-routes |
| Friends | [x] | friends/requests accept/reject + FriendService |
| Passkeys | [x] | WebAuthn register/authenticate with real CBOR/COSE/signature fixtures |
| Todos | [x] | categories/workspaces/todos/subtasks/due-date + parse matrix |
| Notes | [x] | CRUD/categories/newest + md/zip import |
| Tags / Shortcuts | [x] | CRUD + shortcut reorder |
| Check-in | [x] | index/store, per-date upsert, validation |
| Clipboard | [x] | text/image flows with `Storage::fake` |
| Time tracking | [x] | categories/projects/entries, start/stop 409s, statistics |
| Finances | [x] | budget CRUD, wealth fields/values |
| Libraries: books/music | [x] | CRUD + genres + Hardcover/Deezer fakes |
| Libraries: games/movies/links/quotes/recipes/plants/covers | [x] | CRUD + Steam/Imdb/Tmdb/Bgg/Chefkoch fakes |
| AiService / Recommender / Releases | [x] | OpenAI client injected via `ClientContract` |
| Calendars | [x] | calendar/event/attachments; DAV-backed |
| Contacts | [x] | address books/contacts/photos (DAV-backed) |
| Feeds | [x] | subscriptions/items/available/search/test; FeedReader mocked |
| Weather | [x] | today endpoint + WeatherService caching |
| Statistics | [x] | admin overview + DAV counts |
| Dashboard quick-add | [x] | detection matrix + commit paths |
| Dev requests | [x] | CRUD, one-vote-per-user, comments |
| Notifications / Telegram | [x] | list/read/settings/push subscribe + token/request/alerts |
| Export | [x] | store/status/download + ProcessExport + TodoExport CSV + DeleteExpiredExports |
| Bots | [~] | ConversationState, connect, quick-add, day/todos; telegram send flow via `Http::fake` |
| DAV services/backends | [x] | calendars/events/address books/contacts/principals/sharing/VCardPhotoProcessor/factory |
| Queue jobs | [~] | maintenance jobs covered; some assertions weak (`expect(true)->toBeTrue()`) |
| Artisan commands | [~] | user/dav/notification/telegram/reminder commands; reminder commands only assert exit code |
| OpenAPI vs routes | [x] | drift pinned |

## Known gaps (thin / untested)

1. **External providers that lack HTTP seams** — `GoodreadsService` (raw
   `file_get_contents`) and `DiscogsService` (third-party client) are not
   unit-tested; only their DTO parsing is covered indirectly.
2. **Image manipulation** — Imagick/imgproxy is stubbed with a no-op
   `ImageTransformationService` double; jobs are asserted by dispatch/result,
   not real pixel work. Any real image-processing logic is untested.
3. **FeedReader / SimplePie** — mocked in feature tests; the actual SimplePie
   fetch/parse path is untested (needs a test seam or fixture feed files).
4. **DAV low-level backends** — Sabre's PDO backends are exercised through the
   service layer on the SQLite mirror, but raw PostgreSQL-flavored SQL paths
   are not covered.
5. **Weak assertions** — `ScaleCovers` asserts a tautology; daily-reminder
   commands only assert exit code (they send only at 07:00/20:00 local).
6. **Pre-existing command bug not caught** — `CreateDavPrincipalsForExistingUsers`
   passes a `User` model into `Principals::create(string $email)` (Eloquent
   `__toString` yields a garbage URI); the test only asserts `assertSuccessful()`.

## Recommended next iterations (prioritized)

1. **Measure first.** Install `pcov`/`xdebug`, run `--coverage-text`, and record
   real per-module percentages. Everything else becomes concrete once we have a
   baseline and can gate on `--min`.
2. **Add CI coverage gate.** In CI: run the suite with coverage, upload the
   Clover/HTML artifact, and fail the build if line coverage drops below a
   chosen threshold (start ~70–80% for `app/`, tighten module by module).
3. **Close the external-service seams.**
   - Refactor `GoodreadsService` to use `Illuminate\Support\Facades\Http`
     (replace `file_get_contents`) so it can be faked.
   - Wrap `DiscogsService`'s third-party client behind a small adapter /
     injectable HTTP transport.
   - Then add fixture-driven parsing tests for both.
4. **Make FeedReader testable.** Extract SimplePie instantiation into an
   injectable factory (like `AiService`'s `ClientContract`) and add tests that
   parse real RSS/Atom fixture files.
5. **Harden the weak tests.** Replace the `ScaleCovers` tautology with a
   real file assertion; make the daily-reminder commands testable by injecting
   the "current hour" (e.g. a clock) and assert the notification is actually
   dispatched; add a targeted test for `CreateDavPrincipalsForExistingUsers`
   that asserts a correct principal URI (and fix the underlying `create($user)`
   call to pass `$user->email`).
6. **Raise the bar on jobs/commands.** For each `ShouldQueue` job, assert
   success + failure paths and queue middleware; for each artisan command,
   assert actual side effects, not just exit codes.
7. **Migrate the dead DAV code.** Remove or re-wire the legacy
   `NextcloudContact` / `NextcloudCalendarEntry`-referencing branches in
   `VCardHelper`/`ICalendarHelper` so the remaining branches can be covered
   instead of skipped.
8. **Coverage annotations.** Add `@covers`/`@coversNothing` on focused unit
   tests so `--coverage-html` reports are meaningful and slow classes can be
   excluded from the aggregate.
