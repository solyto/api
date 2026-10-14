# Verdict: Health response backwards compatibility

id: steep
reviewer: @reviewer
date: 2026-10-14

## Review

Reviewed `git diff main...HEAD` (base `main` from `.manigot/manigot.json`) on
branch `feature/steep_health-response-backwards-compatibility`. The only
changes outside the job dir are `routes/api.php`, `tests/Unit/ClientContractTest.php`
and `docs/STANDARDS.md`. All three are covered by tasks.md and implementation.md.

TASK-1: PASS
notes: `routes/api.php:61-73`: the body comes from `ApiResponse::success([...])`, so
`success`, `message`, `data{status,app,version}` and `timestamp` stay. Then
`setData([...getData(true), 'app', 'version'])` adds the two keys at the top level
with the same values. It adds no raw `response()->json` (7.1) and no new `ApiResponse` API.
Because the change only adds keys, both old and new clients accept the body.

TASK-2: PASS
notes: `tests/Unit/ClientContractTest.php` is new. It has `uses(RefreshDatabase::class)` and
the header comment from docs/TESTING.md. There are two unauthenticated `it()`s: one checks
top-level `app === 'solyto'` plus a non-empty `version`; the other checks
`success`, `data.status`, `data.app`, a non-empty `data.version`, and that `data.version`
equals the top-level `version`. `tests/Unit` gets `Tests\TestCase` from `tests/Pest.php`,
so `getJson` works. Ran it in isolation: 2 tests, 11 assertions, green.

TASK-3: PASS
notes: `docs/STANDARDS.md`: the 1.5 body now describes the current closure, and the 1.5
exception note calls health a pinned legacy contract (13.5) and points at the
test. 7.1 no longer says "the one exception to fix". Only health-route sentences
changed, and 13.5 is untouched.

TASK-4: PASS
notes: I re-ran both commands in the worktree. `composer test` exits 0 (2216 assertions;
the 779 warnings are all `file_get_contents(/workspace/.env)` because the worktree has
no `.env`, which is environmental). `composer analyse` exits 0 ("No errors").
No local server was running, so the curl/jq check was not run. The TASK-2 test asserts
the same conditions, and implementation.md says this, as the task allows.

## Security

none: the change only adds two public, already-exposed values to an unauthenticated route.

## Overall

APPROVED

No blockers.
