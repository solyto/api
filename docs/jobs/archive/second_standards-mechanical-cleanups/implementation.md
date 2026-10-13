# Implementation: Standards: mechanical cleanups

id: second
developer: @developer
date: 2026-10-13 (round 2, after the NEEDS WORK verdict)

## Summary

I did all the mechanical items from `docs/STANDARDS_BACKLOG.md` that the
brief lists. Round 1 did the unblocked tasks. In round 2 the owner gave the
decisions recorded in `brief.md` → "Owner decisions", and with those I
finished TASK-4, 5, 8, 10 and 15. TASK-9 is done apart from `docs/CLAUDE.md`,
which the owner descoped.

**Verification (round 2).** The owner approved `composer install` from the
existing lock file, so the suite now runs in the worktree:

- **Final state:** `php artisan test` → 714 tests, 1903 assertions, no
  failures.
- **Baseline:** for comparison I rebuilt `main` file by file into a scratch
  directory with its own autoloader (`git archive` is blocked). It has the
  same 714 tests.
- **Failures seen during runs, none caused by this branch:**
  - `ExportTest` fails when the local `storage/app/public` folder is missing,
    on `main` too. That is a local setup issue, not a code problem.
  - `PasskeyTest` failed once on the branch and then passed 6 out of 6
    reruns. It is flaky (random EC keys), and the branch doesn't touch it.
- **The 712–714 "warnings"** are only `file_get_contents(.env)`: the worktree
  has no `.env`.
- **OpenAPI:** I parsed the spec with `OpenApi\Generator` and validated it:
  268 operations, **0 warnings**. `main` has 14, all duplicate-`operationId`
  warnings that TASK-2 fixes.

## Changes

TASK-1: `config/app.php` has a new `'version' => env('API_VERSION', '1.0.7')`. In `routes/api.php`, `v1/health` now returns `ApiResponse::success([...])` with `config('app.version')`. **Breaking for consumers:** the fields now sit under `data`.

TASK-2: The Libraries operationIds are now `searchLibrary{Books,Movies,Games,Music}` and `importLibrary{Book,Movie,Game,Music,Recipe}`. No duplicates remain.

TASK-3: All 137 inline `success`/`message` envelopes in 20 controller files now use `allOf={…SuccessResponse}`. The 27 blocks that only had a message also got `data` (nullable).

TASK-4: (OQ-1: paths are relative to the server URL `/api/v1`.) `/api` or `/v1` is removed from all 268 `path="…"` values in 47 controller files, so they now read `path="/todos"`. In `tests/Unit/OpenApiSpecTest.php`, the prefix-normalising loop is gone. The test now asserts `servers[0]->url === '/api/v1'` and maps each path to `api/v1/<path>`. The drift list was already written as route URIs, so it is unchanged. I checked that the test fails on a leftover `/api/` prefix. `STANDARDS.md`: rule 2.1 is rewritten and the section 2 intro is updated. OQ-1 moved to Decisions, and the history note is adjusted.

TASK-5: (OQ-2, reversed: **keep** `ValidationErrorResponse`.) 21 validation 422s were wrongly pointing at `ErrorResponse`, and now reference `ValidationErrorResponse`. They are in 9 files: Todos ×10, Notes ×6, Calendars ×2, CheckIn, Users ×2. The 2 422s in `FeedController` stay `ErrorResponse`, because the code returns `ApiResponse::error('Feed URL doesn't seem valid.', 422)` with no field `errors`. I updated `STANDARDS.md` rule 2.4, the error note in rule 2.3, and Decisions (OQ-2 removed). The backlog entry is reworded to match.

TASK-6: `{todo}` ×6, `{note}` ×3, `{entry}` ×3 and `{project}` ×3 are now `type="string", format="uuid"`. `{subtask}` stays `integer`, because the model has no `HasUuids`.

TASK-7: In `tests/Pest.php`, removed `toBeOne`, `toBeBoolean`, `greaterThan`, `lessThan` and `something()`. 22 call sites now use the native Pest methods. `toBeEmail`, `expectApiSuccess` and `expectApiError` are kept.

TASK-8: (Owner chose option a.) Deleted `tests/Unit/Pest.php` and `tests/Feature/Pest.php`, and added `tests/Feature/.gitkeep`. I confirmed that `app/Api/Pest.php` was never loaded: without a per-file `uses()`, a test fails with "no such table". So I deleted it and bound `RefreshDatabase` once in `tests/Pest.php` with `uses(RefreshDatabase::class)->in('../app/Api')`. All 44 per-file `uses(RefreshDatabase::class)` lines and their imports are removed. Four module test files that had no database setup now get one too; that is harmless. `TESTING.md` (binding paragraph, Feature row) and `STANDARDS.md` 12.9 are corrected.

TASK-9: Deleted root `AGENTS.md` and root `implementation.md`. `docs/CLAUDE.md` is **descoped by the owner**: it is mounted into the container dynamically, so it is ignored completely.

TASK-10: `composer.json` now has `"name": "solyto/api"`, a description taken from the project context, and `"license": "AGPL-3.0-only"`.

TASK-11: The Pest dev constraints are now `^4.4` and `^4.0`. `composer.lock` is unchanged, as the brief asks.

TASK-12: Untracked `bootstrap/cache/packages.php` and `services.php`, added `bootstrap/cache/*.php` to `.gitignore`, and added `bootstrap/cache/.gitkeep`. I checked that `composer install` regenerates the files and git ignores them.

TASK-13: In `bootstrap/app.php`, the non-API `AuthenticationException` now returns `ApiResponse::unauthorized()` (401). It used to redirect to the unregistered `route('login')`.

TASK-14: Added return types to `User::friends()`, `sentFriendRequests()`, `receivedFriendRequests()` and `allFriendRequests()`.

TASK-15: (OQ-5 approved.) All 37 models now have `scopeForUser(Builder $query, string $userId)` and import Eloquent's `Builder`. Every scope caller already passes a string user id; the `forUser($user)` calls are factory states, not this scope. `STANDARDS.md` rule 5.2 is updated and OQ-5 removed.

TASK-16: Hand formatting in `TodoResource`, `ApiResponse`, `ImportFeeds` and the `bootstrap/app.php` imports. Pint was not run (OQ-6).

TASK-17: Removed the 6 listed narration `Log::info` calls and the `Log` imports they left unused.

## Known issues / follow-ups

- **`composer.lock` content-hash is stale** after TASK-10/11. `composer validate` warns until someone runs `composer update --lock`.
- **`php artisan l5-swagger:generate` fails** with "Required @OA\PathItem() not found", and it fails the same way on `main`. Calling `OpenApi\Generator` directly works, so it's probably a mismatch between the l5-swagger 10 config and swagger-php 6.
- **Local test setup (not a repo issue):** `storage/` is intentionally not in git, because it holds data about real systems. To run the suite in a fresh worktree, first create `storage/framework/{views,cache/data,sessions,testing}` and `storage/app/public`. Without them, `composer install`'s `config:clear` script and `ExportTest` fail.
- **`PasskeyTest` is flaky**: it intermittently fails with "Failed to load EC public key." This is unrelated to this branch.
- **TASK-17:** 5 similar narration `Log::info` calls that the brief doesn't list remain in `Calendars/Jobs/ImportCalendars.php`, `Contacts/Jobs/ImportContacts.php` and `Contacts/Jobs/RefreshContactsCache.php`.
- **Second inline-envelope variant:** `data` + `message` without `allOf`, in 46 places across 12 controller files. Rule 2.3's check doesn't catch it.
- **TASK-6 follow-up:** other `HasUuids` models (Libraries, Contacts, Calendars, Feeds, Shortcuts) probably still have path params mistyped as `integer`.
- **`STANDARDS_BACKLOG.md` checkboxes** for the items done here are not ticked, same as round 1. Tick them at merge if that's the workflow.
