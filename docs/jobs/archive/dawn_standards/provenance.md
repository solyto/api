# Provenance map — human vs AI

id: dawn
date: 2026-10-13

Purpose: decide which file to copy from when a rule in `docs/STANDARDS.md`
needs a "good sibling". The brief says "prefer the human path"; this file
records how far git can actually tell the two apart.

## What git can and cannot tell

- History starts at `37f9642` "Initial Commit" (2026-04-07, 649 files,
  56 941 insertions). Everything before that date is squashed into one
  commit; 131 commits follow, all authored `Leander Muskalla` (three email
  variants) except 5 `dependabot[bot]` bumps.
- **AI commits carry the owner's name.** There is no `Co-authored-by`
  trailer, no bot author. Attribution therefore rests on three signals:
  1. a job folder under `docs/jobs/archive/*` whose brief matches the commit
     (strong);
  2. a commit message in the manigot style (`test:`, `fix:`, `chore:`,
     `refactor:`, `docs:`, `[ad-hoc]`) — the owner's own messages are plain
     sentences ("Fix calendar sharing, was completely broken", "Testing,
     testing, testing") (medium);
  3. burst size: one commit touching 110 files is a job run, not an evening
     (weak alone).
- The owner's statement in `docs/jobs/archive/online_php-laravel-api-best-pratices/brief.md`
  ("Most of the API I've developed myself … When I let AI do stuff, it's
  sometimes okay") is the only ground truth for the Initial Commit. Whether
  parts of that commit were AI-assisted cannot be recovered from git; the
  style markers below (typed constants, `fn()`, `!$`, aligned `=>`) are
  consistent across it, so it is treated as one hand.

## Known AI job commits

| Commit | Date | Job folder | Footprint |
| --- | --- | --- | --- |
| `0d932a3` create tests | 2026-08-14 | `delay_create-tests` | 110 files, +7 565: all `app/Api/*/Tests`, `tests/*`, `app/Api/Pest.php`, factories |
| `359c757`…`7b6ec72` (`refactor:`/`fix:`/`chore:`/`test:` ×5) | 2026-08-14 | same job | http seams in `Services/External/*`, DAV helper dead-code removal, `covers()` |
| `4f7deeb` ci / `703d82a` remove workflow | 08-14 / 08-16 | same job + owner revert | `.github/workflows/tests.yml` |
| `76e77a0` upcoming events erroir | 2026-08-22 | `slight_upcoming-events-erroir` | `CalendarService`, `CalendarApiTest` (+150), `app/Dav/Backend/AppCalendarsPDO.php` (+47) |
| `b10b48c` long-term cache store | 2026-08-25 | `tunnel_add-new-long-term-cache-store` | `config/cache.php`, `config/database.php`, `tests/TestCase.php`, **`bootstrap/cache/*.php` (generated, committed)** |
| `ed8d601` future releases | 2026-08-25 | `issue_future-releases` | `Libraries/Jobs/Grab*Releases.php`, `UserCacheService` (forever TTL), `GrabReleasesTest` |
| `3918a3a` spotify integration, `c302121` integrations endpoint | 2026-08-27 | `post_spotify-integration` | `SpotifyService` (208), `LibraryReleases` (+64), `LibraryMusicController`, 4 test files |
| `62dc51c`, `641b4ec` | 2026-08-27 | ad-hoc (root `implementation.md`) | `tests/TestCase.php`, `.gitignore` |
| `e0f4e1f` deactivate movie release notifications | 2026-10-08 | `elect_deactivate-movie-release-notifications` | `routes/console.php` comment block |
| `4648dd9` notebook: journal folders | 2026-10-10 | `home_notebook-journal-folders` | `Notes/*` (controller +54, service, model, migration ×2, tests +152), `UserObserver` |
| `588773d` time tracking improvements | 2026-10-10 | `symbol_time-tracking-improvements` | `TimeTracking/*` (controller +33, resources, tests +141), migration |
| `acb653b` php/laravel/api best pratices | 2026-10-10 | `online_php-laravel-api-best-pratices` | `docs/API-CONVENTIONS.md` + 8 `docs/CONVENTIONS-*.md`, `docs/AGENTS.md`, `README.md:46` |
| `780d58d`, `c48fa49` | 2026-10-10 | no job folder; same day as two jobs, plain messages | treated as owner follow-ups |

Everything else — 2026-04-07 to 2026-08-08 and the plain-sentence commits
after that — is treated as the owner's.

## Module map

"Reference file" = the file to quote as the good example for that layer.
Where a module is mixed, the human part is named.

| Module | Verdict | Basis | Reference file(s) |
| --- | --- | --- | --- |
| `Todos` | human (tests AI) | 8 commits, all plain messages (05-14 … 06-16); only `0d932a3` added `Tests/` | `Controllers/TodoController.php`, `Services/TodoService.php`, `Requests/StoreTodoRequest.php`, `Resources/TodoResource.php`, `Models/Todo.php` — **the reference module** |
| `Notes` | mixed | owner's base (Initial Commit) + AI journal-folder job (`NoteCategoryController` +54, `NoteCategoryService`, `NoteService` +11) | `NoteController.php` (owner, `/v1/` + `allOf`); AI part: `NoteCategoryController` hidden/journal endpoints |
| `TimeTracking` | mixed | owner's base; AI job touched the entry controller, resources, service (12 lines) | owner: `TimeTrackingService.php`; the project-ownership check in `TimeTrackingEntryController:167-170` predates the job (not in its diff) → owner |
| `Users` | human (tests AI) | 23 commits, all plain messages; AI only in `Tests/` and `Observers/UserObserver.php` (+7, journal job) | `AuthController.php`, `AuthService.php`, `UserSettingsController.php` (`/v1/` + `allOf`, 14 ops) |
| `Calendars` | human (+AI test file, +AI DAV backend lines) | 9 commits plain; AI: `CalendarApiTest` (+150), `CalendarService` (8 lines) | `CalendarController.php` is the owner's and is the file with the most `try/catch` (10) and `/api/` annotation paths — **owner's code breaks several proposed rules** |
| `Contacts` | human | 6 plain commits incl. `b6e33ff` repair command | `ContactController.php` (same shape as Calendars) |
| `Libraries` | human (+AI: Spotify, release jobs, external-service tests) | 31 commits; AI: `SpotifyService`, `LibraryReleases` Spotify path, `Grab*Releases` jobs rework, `Tests/*ServiceTest` | `LibraryBookController.php` / `LibraryBookService.php` are the owner's; the `/api/` + inline-envelope annotation style here is **the owner's older style** (June 2026, "Import & Search for Libraries" `af44188`, 53 files) |
| `Feeds` | human (+AI http seams/tests) | 11 commits; AI: `359c757` seams, `2bf4e32` tests, `739f144`? plain message → owner | `FeedService.php`, `FeedController.php` |
| `Dashboard` (QuickAdd) | human (+AI 10-line Spotify touch) | 9 commits plain | `Shared/Services/QuickAddService.php`, `IntegrationGateway.php` |
| `Export` | human | 7 plain commits incl. "Refactored ExportService to be less of a mess" | `Services/ExportService.php` |
| `DevRequests`, `Finances`, `Clipboard`, `Shortcuts`, `Tags`, `Telegram`, `Statistics`, `Weather`, `CheckIn`, `Notifications` | human (tests AI) | Initial Commit + 0–3 plain commits; AI only in `Tests/` (and `Notifications/Commands/GenerateExampleNotificationsCommand.php`, 09-16, plain message → owner) | `TagController.php` for the minimal CRUD shape |
| `app/Shared` | human (+AI: `UserCacheService` forever-TTL branch, `QuickAddService` 10 lines) | 22 commits plain | `UserCacheService.php`, `IntegrationGateway.php` |
| `app/Bots` | human | 12 plain commits; tests AI | `SolytoBot.php`, `Messages/SolytoMessage.php` (translation-key enum) |
| `app/Dav` | human (+AI: `5e2e4aa` dead-code removal, `76e77a0` +47 in `AppCalendarsPDO`) | 12 commits | describe-only in this job |
| `app/OpenApi` | human | 1 commit | `OpenApiSpec.php` |
| `tests/*`, `app/Api/*/Tests/*`, `app/Api/Pest.php` | **AI** (job `delay`, 2026-08-14) + owner's `6f7ae7e` "Testing, testing, testing" (5 files, +114) | | `TodoApiTest.php` for the API-test shape; the factory/model test files (`*Test.php` without `Api`) are the AI job's bulk |
| `docs/CONVENTIONS-*.md`, `docs/API-CONVENTIONS.md` | AI (job `online`) | | superseded by `docs/STANDARDS.md` in this job |

## Two consequences for the rules

1. The annotation split (`/api/` + inline envelope vs `/v1/` + `allOf`) is
   **not** human-vs-AI; it is the owner's June style (Libraries, Calendars,
   Contacts, Feeds, Finances, TimeTracking, …) vs the owner's later style
   (Todos, Notes, Users). `OpenApiSpec.php` declares `@OA\Server(url="/api/v1")`,
   so neither prefix is right; the rule has to pick one and the owner
   confirms it (open question in `STANDARDS.md`).
2. `try/catch` → `report()` → `ApiResponse::error(…, 500)` in
   `CalendarController`/`ContactController`/`AuthController` is the owner's
   pattern, not an AI one. The rule against it still stands (the central
   map in `bootstrap/app.php` already does this), but the doc says so
   plainly rather than blaming the AI.
