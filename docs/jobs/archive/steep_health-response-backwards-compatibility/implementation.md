# Implementation: Health response backwards compatibility

id: steep
developer: @developer
date: 2026-10-14

## Summary

`GET /api/v1/health` now returns the full `ApiResponse::success` envelope
(`success`, `message`, `data: {status, app, version}`, `timestamp`) and also
has `app` and `version` at the top level, so solyto/app <= 1.0.15 self-hosted
setup accepts the server again. A client contract test pins both shapes, and
STANDARDS 1.5 / 7.1 now describe the route as a pinned legacy contract.

## Changes

TASK-1: `routes/api.php`: the health closure builds the body with
`ApiResponse::success([...])`, then merges `app` and `version` into the top
level with `$response->setData([...$response->getData(true), 'app' => …, 'version' => …])`.
No raw `response()->json` (rule 7.1) and no new `ApiResponse` API.

TASK-2: `tests/Unit/ClientContractTest.php` (new, `uses(RefreshDatabase::class)`,
header comment per docs/TESTING.md). Two tests, both unauthenticated:
top-level `app === 'solyto'` and non-empty `version` (what 1.0.15 reads), and
the envelope (`success`, `data.status === 'OK'`, `data.app`, `data.version`,
equal to the top-level `version`). The version is checked as non-empty, not as
an exact string, because the test env falls back to `'1.0.7'`.

TASK-3: `docs/STANDARDS.md`: 1.5 body now describes the current closure
(`config('app.version')` + `ApiResponse::success`, the old `env()` version as
history); the 1.5 exception note calls health a pinned legacy contract (13.5)
and points at `tests/Unit/ClientContractTest.php` instead of the backlog entry;
7.1 no longer calls health "the one exception to fix". 13.5's "Bad" example
is unchanged.

TASK-4: `composer test` exits 0 (all tests pass) and `composer analyse` exits 0 ("No errors").
No local server was running (`curl localhost` → connection refused), so the
brief's curl/jq check was not run; the TASK-2 test covers the same assertions.

## Known issues / follow-ups

- Worktree setup: `vendor/` was missing, so I ran `composer install` from the
  existing lock file (no packages added or changed). The gitignored runtime
  dirs `storage/framework/*`, `storage/app/{public,private}` and
  `bootstrap/cache` were also missing. Without them the ExportTest CSV tests fail with
  `fopen` errors. I created them; nothing is committed.
- The suite reports ~779 warnings: `file_get_contents(/workspace/.env)`
  because the worktree has no `.env`. This is environmental and not caused by this job.
- `.env.testing` shows as modified in `git status` but is a mounted device
  (git cannot hash it); it was not touched or committed.
- `docs/backlog/health-response-backwards-compatibility.md` was not touched
  (per tasks.md open point 3).
