# Verdict: Client contract tests for frontend 1.0.15

id: minor
reviewer: @reviewer
date: 2026-10-14

## Review

Branch: `feature/minor_client-contract-tests-for-frontend-1-0-15` (matches brief). Base: `main`.
`git diff main...HEAD` touches only `tests/Unit/ClientContractTest.php` (additions only; the four existing tests are unchanged), the new `tests/Fixtures/client-contract/1.0.15-routes.json`, and the job dir. Nothing under `tmp/` is tracked. No app, config or DAV changes.

Verified by reviewer in the worktree:
- `composer test`: exit 0. No failures. All 26 `Client contract` tests pass. The warnings come from the missing `.env` in this sandbox.
- `composer analyse`: exit 0, "No errors".

TASK-1: PASS
notes: The fixture has 241 unique `{method, uri}` pairs and 18 `unused` entries, with no duplicates. I checked it against `/workspace/tmp/solyto-app-1.0.15` (read-only):
- Every leaf in `apiRoutes.ts` (255) is either covered by a fixture URI or listed in `unused`.
- Each `unused` key has no call site outside `apiRoutes.ts`.
- Spot-checked HTTP methods against the `ApiService` verbs (`create`/`post`/`postRaw`/`postFormData`/`uploadFile` → POST, `update`/`put` → PUT, `image`/`list`/`get` → GET). Checked subtasks (POST/PUT/DELETE), contact photo (POST/DELETE), clipboard image (GET), time-tracking stop (POST), and the export download raw `fetch` (GET). All correct.
- The direct `fetch` to `GET api/v1/health` is included. The other raw `fetch` calls are a service worker and nominatim, so they are correctly excluded.

TASK-2: PASS
notes: The route test matches without dispatching. It fails with the offending pair on 404/405 and asserts that the matched `uri()` equals the fixture URI, which guards against a static-vs-param route answering the call. It also asserts the fixture is non-empty. The dummy value `1` doesn't collide with any static segment.

TASK-3: PASS
notes: login, refresh and `users/me` match `types/auth.ts` `LoginResponse`/`User`. I confirmed that 1.0.15 always calls `users/me` (`loadAdditionalData`) after login and after a refresh (`Auth.svelte.ts:63-66`). So leaving out the unread `widgets`/`ai_enabled`/`additional_info_last_updated_at` is justified (grep finds no reads).

TASK-4: PASS
notes: The note list and categories with nested `children` match `types/note.ts:28-36` and `Notes.svelte.ts:82-85`.

TASK-5: PASS
notes: Todo list, categories and workspaces (including `categories[]`) are covered.

TASK-6: PASS
notes: Data is created through the API, the same way the module tests do it, with no DAV config touched. The month is fixed, so the test doesn't depend on today's date. Leaving out contact `title` is documented as an old gap that was never returned, and is not a regression. It is correctly reported rather than "fixed".

TASK-7: PASS
notes: Statistics uses the same `?from=…&to=…` query as `TimeTracking.svelte.ts:149`. It asserts `by_project[].project_id`, `total_minutes`, and that `color` is present while its value is null.

TASK-8: PASS
notes: Book, music, movie and game list tests are in place, and none are red.

TASK-9: PASS
notes: The notification settings test covers all 36 keys in `types/notification_settings.ts`. The check-in settings test (`CheckInData.svelte.ts:46-62`) is the only other settings GET. The `users/me` settings are already covered by TASK-3, which settles open point 2 without duplicating a test.

TASK-10: PASS
notes: I re-ran the checks independently, as described above. Both exit 0.

Non-blocking observations (no action needed for this job): the Known issues in `implementation.md` (contact `title`, event `calendar_id` type, movie genres resource) are correctly reported, not fixed, as the brief requires.

## Security

none (test-only change; no secrets in the diff).

## Overall

APPROVED — no blockers.
