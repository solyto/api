# Backlog: Client contract tests for frontend 1.0.15

created: 2026-10-14

## What
Write the first set of client contract tests (`docs/STANDARDS.md` 13.6, `docs/TESTING.md` → Client contract tests). They pin what the 1.0.15 frontend (the first F-Droid release) calls and reads.

**Where:**
- Tests: `tests/Unit/ClientContractTest.php`, one file, with `uses(RefreshDatabase::class)`.
- Route list: `tests/Fixtures/client-contract/1.0.15-routes.json`.
- Nothing else in the repo changes. No app code, no config, no `phpunit.xml`/`pest.php` change (`tests/Unit` is already a suite).

**Source:** `solyto/app` at tag `1.0.15` (`22c5a68`). It is public; fetch it read-only, for example `https://api.github.com/repos/solyto/app/tarball/refs/tags/1.0.15`. Do not commit or vendor it, and do not write it outside the workspace scratch area.

**Steps:**
1. **Route inventory.** Collect every API call 1.0.15 makes:
   - every entry in `src/lib/config/apiRoutes.ts`, with the HTTP method taken from its call sites (`apiService.get/list/create/update/put/post/delete/...` in `src/lib/state/` and `src/lib/components/`)
   - direct `fetch` calls, such as `src/routes/setup/+page.svelte:34` → `GET /api/v1/health`

   Skip entries that are never called (for example `notes.export`, `apiRoutes.ts:84`) and list them under `"unused"`. Write the list to the fixture as `{method, uri}` pairs, with path parameters written as in `routes/api.php`.
2. **Route test.** One test that loops over the fixture and asserts each `{method, uri}` matches a registered route (through the router, without performing the request). This makes "a route 1.0.15 calls was removed or renamed" fail in `composer test`.
3. **Field tests.** For each endpoint below, one test that does the request with factory data and asserts only the keys 1.0.15 reads, using `assertJsonStructure` / `assertJsonPath` through the envelope. Take the keys from `src/lib/types/*.ts` and from the components that read them. Each test gets a header comment: `// Since 1.0.15. solyto/app <file>:<line>`.
   - `GET health`: top-level `app` and `version` (`src/routes/setup/+page.svelte:37`)
   - auth: `login`, `refresh`, and the current user with settings
   - notes: `GET notes`, `GET notes/categories` (with `children`)
   - todos: list, categories, workspaces
   - calendars: list and events
   - contacts: address books and contacts
   - time tracking: projects, entries, `entries/statistics` (`by_project[].project_id`, `total_minutes`, `color` key present)
   - libraries: list endpoints for books, music, movies, games
   - notification settings and user settings

   Other endpoints can follow in later entries.

## Why
Since the F-Droid release, installed apps run 1.0.15 against every new API deploy. The 1.0.15 → `main` check on 2026-10-14 found a break (health shape, `f3545db`) that review didn't catch. These tests make that kind of break fail `composer test`, which is already the done gate, so features can still be iterated on fast.

## Out of scope
- Fixing anything a test exposes, apart from what the dependencies below cover.
- Endpoints not listed in step 3.
- DAV (`routes/web.php`, `pgsql`): its clients are third-party.
- Frontend changes.

## Notes
Depends on `health-response-backwards-compatibility`: the health test is red until that lands, so do that entry first or in the same job. `hidden-note-categories-opt-in` adds its own absence test to this file.

If a field test is red for any other reason, stop and report it. Don't adjust the test and don't change the API: rule 13.6 says red means the API is wrong, and fixing the API is the owner's call.

Check: `php artisan test tests/Unit/ClientContractTest.php` passes, then `composer test` and `composer analyse` exit 0.
