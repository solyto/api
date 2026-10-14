# Tasks: Client contract tests for frontend 1.0.15

id: minor
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

Branch checked: the worktree HEAD is `feature/minor_client-contract-tests-for-frontend-1-0-15`, which matches `brief.md`.

## Context found while reading

- **Both dependencies have landed.** `tests/Unit/ClientContractTest.php` already exists. It has `uses(RefreshDatabase::class)` and one `describe('Client contract', …)` block with:
  - the health top-level test (from `steep_health-response-backwards-compatibility`). This already covers the brief's `GET health` item. `routes/api.php:61-73` returns `app`/`version` at the top level.
  - a health envelope test with no header comment. It came from the steep job, so leave it as it is.
  - two absence tests for notes/categories (from `virus_hidden-note-categories-are-opt-in`).
  Add the new tests **inside the existing `describe` block**. Do not create a second file or block, and do not edit the existing four tests.
- `tests/Fixtures/client-contract/` already exists (it holds `1.0.15-migrations.txt` from the migration-guard job). The new fixture goes next to it as `1.0.15-routes.json`.
- `tests/Unit` gets `Tests\TestCase` from `tests/Pest.php` but no `RefreshDatabase`. The file declares that itself. Helpers available: `makeUser()`, `authHeaders()`, `expectApiSuccess()`.
- Calendars and contacts are DAV-backed. In tests the `pgsql` connection is swapped for a file-backed SQLite (`tests/TestCase.php:42-47`) and wrapped in a transaction. Set up data the way `app/Api/Calendars/Tests/CalendarApiTest.php` and `app/Api/Contacts/Tests/ContactApiTest.php` do. Do **not** touch the DAV/pgsql config.
- Scratch area for the frontend source: `/workspace/tmp/` is git-ignored (`.gitignore:46`). Extract the 1.0.15 tarball there, in its own empty subdirectory (for example `/workspace/tmp/solyto-app-1.0.15/`). Only read it: never run anything from it, and never commit it.
- Several routes are registered with `Route::apiResource('/', …)->parameters(['' => 'x'])` (todos, notes, libraries, time-tracking entries…). Their real `$route->uri()` can look unusual (for example `api/v1/todos/{todo}`). Write fixture URIs exactly as the router reports them, and confirm with `php artisan route:list --path=api/v1` rather than guessing.
- Static routes are registered before parameterised ones on purpose (`notes/categories/hidden` before `categories/{category}`, `todos/due-date` before `{todo}`, `calendars/order` before `{instanceId}`…). A plain "does some route match" check can be satisfied by the wrong route, so the route test must also compare the matched route's URI with the fixture URI (see TASK-2).

## Task breakdown

TASK-1: Fetch `solyto/app` at tag `1.0.15` (`22c5a68`) read-only into `/workspace/tmp/solyto-app-1.0.15/`. Build the route inventory from `src/lib/config/apiRoutes.ts`, taking each method from its call sites in `src/lib/state/` and `src/lib/components/`, and add direct `fetch` calls such as `src/routes/setup/+page.svelte:34`. Write `tests/Fixtures/client-contract/1.0.15-routes.json` as `{ "routes": [{ "method", "uri" }…], "unused": [...] }`, with path parameters named as in `routes/api.php`. Never-called entries such as `notes.export` (`apiRoutes.ts:84`) go under `"unused"`.
     files: tests/Fixtures/client-contract/1.0.15-routes.json (new)
     depends: none
     risk: medium — this is manual and easy to get incomplete. `apiRoutes.ts` uses `%s`/`%d` placeholders and some calls build URLs dynamically, so each mapping needs a careful grep. A missing entry fails silently; it does not turn red.

TASK-2: Add one route test that loads the fixture and, for each `{method, uri}`, replaces each `{param}` with a dummy segment, builds a `Request`, and calls `Route::getRoutes()->match()` without dispatching. It asserts that a route matched (it catches `NotFoundHttpException`/`MethodNotAllowedHttpException` and fails with the offending pair in the message) and that the matched route's `uri()` equals the fixture URI. It also asserts the fixture is non-empty.
     files: tests/Unit/ClientContractTest.php
     depends: TASK-1
     risk: medium — the dummy values must not collide with static segments (for example a dummy `{yearMonth}` = `widget`). If a 1.0.15 route has no match in today's API, that is a real break: stop and report it (see Open point 1). Do not drop the entry from the fixture.

TASK-3: Field tests for auth and the current user: `POST auth/login` (token/user keys 1.0.15 stores), `POST auth/refresh`, and `GET users/me` including the settings keys 1.0.15 reads. Use the keys from `src/lib/types/*.ts` and the reading code, each test with its `// Since 1.0.15. solyto/app <file>:<line>` header.
     files: tests/Unit/ClientContractTest.php
     depends: none
     risk: medium — login sits behind `throttle:auth-login` and may have a verification/2FA branch (`auth/verify`), so the test user must take the path that 1.0.15 takes. Check `AuthController::login` before writing the assertions.

TASK-4: Field tests for notes: `GET notes` (note fields) and `GET notes/categories` (category fields including `children`). They sit next to the existing absence tests, but are separate from them.
     files: tests/Unit/ClientContractTest.php
     depends: none
     risk: low — the factories exist, and `docs/TESTING.md:172-181` already sketches the category test (confirm its line reference `src/lib/types/note.ts:28-36` against the tag).

TASK-5: Field tests for todos: `GET todos` (list), `GET todos/categories`, and `GET todos/workspaces`.
     files: tests/Unit/ClientContractTest.php
     depends: none
     risk: low — reference module, and the factories exist.

TASK-6: Field tests for calendars and contacts: `GET calendars` (list), `GET calendars/events/{yearMonth}`, `GET address-books`, and `GET address-books/contacts`. Create the data through the API or helpers, the way the existing module tests do.
     files: tests/Unit/ClientContractTest.php
     depends: none
     risk: medium — the data lives on the DAV (`pgsql`→SQLite file) connection with no Eloquent factories, so setup is heavier and the event/recurrence shape is complex. Copy the setup pattern from the existing tests, and do not change it.

TASK-7: Field tests for time tracking: `GET time-tracking/projects`, `GET time-tracking/entries`, and `GET time-tracking/entries/statistics`. The statistics test asserts `by_project[].project_id`, `total_minutes`, and that the `color` key is present (it may be null).
     files: tests/Unit/ClientContractTest.php
     depends: none
     risk: low — the only subtlety is asserting that `color` is present without asserting its value, which `assertJsonStructure` does. Statistics may need a date range parameter, so check which query 1.0.15 sends.

TASK-8: Field tests for the library list endpoints: `GET libraries/books`, `GET libraries/music`, `GET libraries/movies`, and `GET libraries/games`.
     files: tests/Unit/ClientContractTest.php
     depends: none
     risk: medium — STANDARDS 13.4 records an earlier shape change in libraries (recipes `ingredients`). These list resources are wide, and a field 1.0.15 reads may already have changed. If one is red, stop and report it per the brief.

TASK-9: Field tests for notification settings (`GET notifications/settings`) and user settings. User settings means the settings keys 1.0.15 reads. Find out which endpoint returns them (most likely `users/me`, possibly `users/me/settings/check-in`). If TASK-3 already covers them fully, write that in implementation.md rather than duplicating the test.
     files: tests/Unit/ClientContractTest.php
     depends: TASK-3
     risk: low — test-only. The only unknown is where "user settings" comes from (Open point 2).

TASK-10: Run `php artisan test tests/Unit/ClientContractTest.php`, then `composer test` and `composer analyse` in the worktree. Both must exit 0. Confirm that `git status` shows changes only in `tests/Unit/ClientContractTest.php`, `tests/Fixtures/client-contract/1.0.15-routes.json`, and the job dir (nothing under `/workspace/tmp/` is staged).
     files: none
     depends: TASK-1 … TASK-9
     risk: low — verification only. A red contract test here is a stop-and-report, not something to fix (brief, Notes).

## Open points (for the developer to resolve or report; not guessed here)

1. **A red route or field test is a stop.** The brief says to stop and report a red field test without adjusting the test or the API. This plan applies the same rule to the route test (TASK-2): if a 1.0.15 call has no matching route, record it in implementation.md and stop, and do not remove it from the fixture.
2. **"User settings" endpoint.** The brief lists "the current user with settings" (auth) and "user settings" separately. `routes/api.php` has only one settings GET (`users/me/settings/check-in`), and everything else is a PUT. Check what 1.0.15 actually reads and from where. If it isn't clear, ask rather than inventing an endpoint to test.
3. **Fixture URI form.** This plan suggests storing URIs as `$route->uri()` reports them (with the `api/v1/` prefix), so the TASK-2 equality check is direct. If the owner prefers prefix-less URIs "as in `routes/api.php`", the test prepends `api/v1/`. Either way, use one form only.
4. **Network access.** TASK-1 needs to reach `api.github.com`/`codeload.github.com`. If the developer's environment can't, the job can't proceed and should report that, rather than rebuilding the inventory from memory.
