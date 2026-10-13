# Tasks: Standards: mechanical cleanups

id: second
analyst: @analyst
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

Branch checked: the worktree HEAD is `feature/second_standards-mechanical-cleanups`, which matches `brief.md`.

## Read this first: blockers and corrections to the brief

- **Blocked on open questions (do NOT implement until the owner answers):**
  TASK-4 (OQ-1, `@OA` path prefix), TASK-5 (OQ-2, `ValidationErrorResponse`),
  TASK-15 (OQ-5, `scopeForUser` retype). All three are still open in
  `docs/STANDARDS.md` → `## Open questions`, and the brief itself says "after OQ-x".
  For OQ-1 the code can't tell us the target spelling (`/todos` vs `/v1/…`).
- **Counts differ from the brief** (checked on 2026-10-13):
  - `uses(RefreshDatabase::class)`: 46 files. 44 are under `app/Api/*/Tests`.
    The other 2 are `app/Bots/Tests/SolytoBotTest.php` and
    `app/Dav/Tests/DavServiceTest.php`. `app/Api/Pest.php` does NOT cover these
    two, so they must keep their `uses(...)`.
  - `function scopeForUser(`: 37 models, not 35. That includes
    `app/Shared/Models/{AiUsage,ExportJob}.php`.
  - `greaterThan`/`lessThan`/`toBeBoolean`/`toBeOne`: 22 single-line matches
    in 6 files. The brief says 23, so one call may be split across lines.
  - `Log::info` in `app/Api/*/{Services,Jobs}`: 11 hits. The brief lists 6.
    See TASK-17.
- **Values the brief does not give:** the composer `"name"`/`"description"`
  (TASK-10). The path-param task (TASK-6) is open-ended ("check each
  controller"). Its scope is limited below.
- **Lock file:** changing `require-dev` constraints (TASK-11) or `"name"`
  (TASK-10) makes `composer.lock`'s `content-hash` stale. Composer will then
  warn "lock file is not up to date". The brief says "Lock file unchanged".
- **No (approval) items** are in this brief. Pint stays out (TASK-16 is done by hand).

Suggested order: the OpenAPI tasks (TASK-2 → 3 → 6, then 4/5 once unblocked)
all edit the same controller docblocks, so do them one after another and run
`tests/Unit/OpenApiSpecTest.php` after each. Everything else is independent
unless a dependency is listed.

## Task breakdown

TASK-1: Move the API version into `config/app.php` (`'version' => env('API_VERSION', '1.0.7')`). Make the `v1/health` route return `ApiResponse::success([...])` with `config('app.version')` (rule 1.5).
  files: routes/api.php (60-67), config/app.php
  depends: none
  risk: medium — the response changes from a bare `{status, timestamp, app, version}` to the `success`/`data` envelope. That can break the separate frontend or uptime monitors that read `status`. Check: `grep -rn "env(" routes` → 0.

TASK-2: Rename the duplicate `operationId`s: `search` ×4 → `searchLibraryBooks`/`…Movies`/`…Games`/`…Music`, and `import` ×5 → `importLibraryBook`/`…Movie`/`…Game`/`…Music`/`…Recipe` (rule 2.5).
  files: app/Api/Libraries/Controllers/Library{Book,Movie,Game,Music,Recipe}Controller.php
  depends: none
  risk: low — docblock-only. The old ids were invalid anyway (duplicates). Check: `grep -rhoE 'operationId="[^"]+"' app/Api | sort | uniq -d` → empty.

TASK-3: Replace the inline `success`/`message`/`data` envelope properties with `allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")}` plus the `data` property, in the 137 blocks across 20 controller files (rule 2.3).
  files: the 20 files from `grep -rln '@OA\\Property(property="success"' app/Api/*/Controllers`
  depends: TASK-2 (same files)
  risk: medium — a large mechanical docblock edit. A broken annotation only shows up when the spec is generated, so run `php artisan l5-swagger:generate` and `OpenApiSpecTest` afterwards. Check: `grep -rn '@OA\\Property(property="success"' app/Api/*/Controllers` → 0.

TASK-4: **BLOCKED on OQ-1.** Unify the `@OA` operation path prefix across all 268 operations to the spelling the owner chooses. Then delete the prefix-normalising loop in `tests/Unit/OpenApiSpecTest.php:128-137` (rule 2.1).
  files: app/Api/*/Controllers/*.php, tests/Unit/OpenApiSpecTest.php (incl. the `OPENAPI_KNOWN_DRIFT` entries that carry the old prefix), possibly app/OpenApi/OpenApiSpec.php (the `@OA\Server` url, if the owner picks "keep /v1, server /api")
  depends: OQ-1 answer; TASK-3
  risk: high — it touches 268 lines plus the drift list. The drift entries must be re-spelled the same way or the test breaks. Check: `grep -rc 'path="/api/' app/Api/*/Controllers` or `path="/v1/'` → all 0.

TASK-5: **BLOCKED on OQ-2.** Replace the 54 `ValidationErrorResponse` refs in 21 files with `ErrorResponse`, then delete the schema at `app/OpenApi/OpenApiSpec.php:54-61` (rule 2.4).
  files: app/Api/*/Controllers/*.php (21 files), app/OpenApi/OpenApiSpec.php
  depends: OQ-2 answer; TASK-3
  risk: low — docblock-only, and both schemas have the same properties. Check: `grep -rn ValidationErrorResponse app` → 0.

TASK-6: Change the `{todo}`/`{subtask}`, `{note}`, `{entry}` and `{project}` path params from `type="integer"` to `type="string", format="uuid"`, but only where the bound model uses `HasUuids` (rule 5.4).
  files: app/Api/Todos/Controllers/TodoController.php, app/Api/Notes/Controllers/NoteController.php, app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php, app/Api/TimeTracking/Controllers/TimeTrackingProjectController.php
  depends: TASK-3 (same files)
  risk: low — docblock-only, but check each param by hand against its model. `Todo`, `Note`, `TimeTrackingEntry` and `TimeTrackingProject` have `HasUuids`; `TodoCategory`, `TodoWorkspace`, `NoteCategory` and `TimeTrackingCategory` don't, so leave those. **Scope note:** other `HasUuids` models (Libraries, Contacts, Calendars, Feeds, Shortcuts) probably have mis-typed params too. They are out of scope for this job; list them as a follow-up.

TASK-7: In `tests/Pest.php`, delete `toBeOne` (18-20) and `function something()` (66-69). Change the `greaterThan`/`lessThan`/`toBeBoolean` call sites to `toBeGreaterThan`/`toBeLessThan`/`toBeBool`, then delete those three extensions. Keep `toBeEmail`.
  files: tests/Pest.php; app/Api/{Feeds/Tests/FeedTest,Telegram/Tests/TelegramBotConnectionTest,Todos/Tests/TodoTest,CheckIn/Tests/CheckInTest,Users/Tests/UserTest,Notes/Tests/NoteTest}.php
  depends: none
  risk: low — the test suite proves it. **Conservative call:** keep `expectApiSuccess`/`expectApiError` unchanged. `docs/TESTING.md:64` says "use them", so deleting them would contradict it. Rewriting tests to adopt them goes beyond a mechanical cleanup, so it's a follow-up. Also grep for multi-line calls (the brief counts 23, the single-line grep finds 22).

TASK-8: Delete `tests/Unit/Pest.php` and `tests/Feature/Pest.php`. Remove `uses(RefreshDatabase::class)` and the then-unused `RefreshDatabase` import from the 44 test files under `app/Api/*/Tests`.
  files: tests/Unit/Pest.php, tests/Feature/Pest.php, 44 files from `grep -rl 'uses(RefreshDatabase::class)' app/Api/*/Tests`
  depends: TASK-7 (overlapping test files; run the suite between them)
  risk: medium — do NOT touch `app/Bots/Tests/SolytoBotTest.php` or `app/Dav/Tests/DavServiceTest.php`, because `app/Api/Pest.php` doesn't cover them. Run `composer test` before and after, and compare pass/fail counts. Check: `grep -rl 'uses(RefreshDatabase::class)' app/Api/*/Tests | wc -l` → 0.

TASK-9: Delete `docs/CLAUDE.md` (an unfilled template), root `AGENTS.md` (0 bytes) and root `implementation.md` (an ad-hoc leftover).
  files: docs/CLAUDE.md, AGENTS.md, implementation.md
  depends: none
  risk: low — first grep the repo for references to these paths (docs links, tooling). The brief allows "delete or fill"; this task deletes.

TASK-10: Set `composer.json` `"name"`, `"description"` and `"license": "AGPL-3.0-only"` in one commit.
  files: composer.json (lines 3, 5, 10)
  depends: none
  risk: low — **unclear: the brief gives no values for `name`/`description`.** They are still the skeleton values (`laravel/laravel`, "The skeleton application…"). Use owner-supplied values and don't guess (e.g. `solyto/api` is only a guess). Changing `name` makes the lock `content-hash` stale.

TASK-11: Constrain `require-dev` `"pestphp/pest": "^4.4"` and `"pestphp/pest-plugin-laravel": "^4.0"` (currently `"*"`), without changing `composer.lock`.
  files: composer.json (37-38)
  depends: none (can share a commit with TASK-10 only if the owner wants; the brief says TASK-10 is "one commit")
  risk: low — the locked versions (pest v4.4.1, plugin v4.1.0) satisfy the new constraints, so nothing is installed. The stale `content-hash` will make `composer install`/`validate` warn. The owner has to choose: accept the warning, or allow a hash-only `composer update --lock`.

TASK-12: Untrack the generated `bootstrap/cache/packages.php` and `services.php` (`git rm --cached`), and add `bootstrap/cache/*.php` to `.gitignore`.
  files: bootstrap/cache/packages.php, bootstrap/cache/services.php, .gitignore
  depends: none
  risk: low — Laravel regenerates them on `package:discover` (composer post-autoload). The deploy lives in a separate repo, so we can't confirm from here that it doesn't rely on the committed copies. Flag this to the owner.

TASK-13: Replace the non-API `AuthenticationException` branch in `bootstrap/app.php:92-98` (it redirects to the unregistered `route('login')`) with a plain 401 response (rule 1.4).
  files: bootstrap/app.php
  depends: none
  risk: medium — the brief allows "return 401 or drop the branch". Dropping it can fall back to Laravel's default, which may also try `route('login')`, so 401 is the safer choice. `routes/web.php` has no auth middleware (DAV does its own auth), so DAV should be unaffected. Still, run the DAV tests, and don't touch the DAV routes.

TASK-14: Add return types to `User::friends()`, `sentFriendRequests(): HasMany`, `receivedFriendRequests(): HasMany` and `allFriendRequests()` (rule 5.1).
  files: app/Api/Users/Models/User.php (101-126+)
  depends: none
  risk: low — the declared types must match the actual returns. `friends()` and `allFriendRequests()` return the result of `->get()->map()`/merge, so check whether that's an Eloquent or a Support `Collection` before declaring.

TASK-15: **BLOCKED on OQ-5.** Retype `scopeForUser($query, $userId)` to `scopeForUser(Builder $query, string $userId)` in the 37 models that define it (the brief says 35) (rule 5.2).
  files: `grep -rl 'function scopeForUser(' app` (37 files)
  depends: OQ-5 answer
  risk: medium — `string $userId` throws a TypeError for any caller that passes an int or a `User` model. Audit all `->forUser(` callers first. Check: `grep -rhoE 'function scopeForUser\([^)]*\)' app | sort | uniq -c` → one signature.

TASK-16: Hand formatting (rule 11.1): fold the three `whenLoaded` closing parens in `TodoResource.php:45-50`; fix the brace at `ApiResponse.php:69` and the double blank line at `:104-105`; fix the brace at `ImportFeeds.php:102`; sort the imports in `bootstrap/app.php:3-15`.
  files: app/Api/Todos/Resources/TodoResource.php, app/Api/ApiResponse.php, ImportFeeds.php (Feeds module), bootstrap/app.php
  depends: TASK-13 (same file, bootstrap/app.php)
  risk: low — whitespace and ordering only. Don't run Pint: it is an (approval) Tooling item (OQ-6).

TASK-17: Remove the narration `Log::info` calls at `NoteImportService.php:59,63,81,89,95` and `RefreshCalendarCache.php:26` (rule 4.9).
  files: app/Api/Notes/Services/NoteImportService.php, app/Api/Calendars/Jobs/RefreshCalendarCache.php
  depends: none
  risk: low — **unclear scope:** the brief's check (`grep -rn 'Log::info' app/Api/*/Services app/Api/*/Jobs` → 0) would also need edits to `Calendars/Jobs/ImportCalendars.php:33,36`, `Contacts/Jobs/ImportContacts.php:33,36` and `Contacts/Jobs/RefreshContactsCache.php:27`, which the brief doesn't list. Owner to confirm; without confirmation, only do the listed lines and note that the check will report 5 remaining hits.
