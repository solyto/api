# Tasks: Health response backwards compatibility

id: steep
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Context (read before starting)

- Branch verified: worktree HEAD is `feature/steep_health-response-backwards-compatibility`, matching `brief.md`.
- Current route: `routes/api.php:61-67`, a closure returning
  `ApiResponse::success(['status' => 'OK', 'app' => 'solyto', 'version' => config('app.version')])`.
  The body is `{success, message, data: {status, app, version}, timestamp}`.
- `app/Api/ApiResponse.php::success()` has no way to add extra top-level keys
  (only `data`, `message`, `status`, `meta`).
- STANDARDS 7.1: `ApiResponse` is the only `response()->json` in `app/`. So
  do not go back to a raw `response()->json([...])` in the route.
  STANDARDS/AGENTS: no new abstractions. So do not add a new parameter or
  method to `ApiResponse` just for this one route.
  Suggested shape (the developer decides the exact code): build the envelope
  with `ApiResponse::success([...])`, then merge `app` and `version` into its
  top level, for example `$response->setData([...$response->getData(true), 'app' => …, 'version' => …])`.
  Then the envelope keys and `timestamp` still come from `ApiResponse`.
- `routes/` is not in PHPStan's `paths` (`phpstan.neon`), so `composer analyse` won't check the route closure. The test is what proves the change works.
- No `@OA` annotation documents `/health` today (grep: no hit in `app/`), so there is no OpenAPI or schema-drift test to update.
- `docs/STANDARDS.md` 1.5 (`:184-186`) **already has** an exception note
  pointing to this backlog entry, and 13.5 (`:916-919`) cites this break as
  its "Bad" example. `docs/TESTING.md:160-170` already shows the health
  contract test as its example.

## Task breakdown

TASK-1: Change the `GET /api/v1/health` closure so the body keeps the full `ApiResponse::success` envelope (with `data.status`, `data.app`, `data.version`) and also has `app` and `version` at the top level, using the same values.
  files: routes/api.php
  depends: none
  risk: low. One public route, an additive change. The only trap is going back to a raw `response()->json` (breaks 7.1) or adding a new `ApiResponse` API (breaks "no new abstractions").

TASK-2: Add a Pest test that pins both shapes of `GET /api/v1/health` with no authentication: status 200, `success === true`, top-level `app === 'solyto'` and a non-empty top-level `version`, plus `data.app === 'solyto'`, `data.version` and `data.status === 'OK'`.
  files: tests/Unit/ClientContractTest.php (new; see open point 1)
  depends: TASK-1
  risk: low. A new test file only. Following `docs/TESTING.md` → Client contract tests: declare `uses(RefreshDatabase::class)` in the file, and add a header comment `// Since 1.0.15. solyto/app src/routes/setup/+page.svelte:37`. In the test env `config('app.version')` comes from `env('API_VERSION', '1.0.7')`, so assert that the value is present and non-empty, not an exact version string.

TASK-3: Update the rule 1.5 exception note in `docs/STANDARDS.md` to say that the health route is a pinned legacy contract (rule 13.5). Point it at the client contract test from TASK-2 instead of the backlog entry. Then update the two lines that still describe the health route as unfixed: 1.5 (`:172-178`, which describes the old `response()->json` + `env()` closure) and 7.1 (`:568`, "the one exception to fix"). Make them match the current code.
  files: docs/STANDARDS.md
  depends: TASK-1, TASK-2
  risk: low. Docs only, but the scope needs care (see open point 2): touch only the health-route sentences, don't reword the rest.

TASK-4: Run `composer test` and `composer analyse` in the worktree. Then run the brief's check (`curl -s localhost/api/v1/health | jq -e '.app == "solyto" and (.version|length>0) and .data.app == "solyto"'`) if a local server is running. Otherwise rely on the TASK-2 test and say so in `implementation.md`.
  files: none (verification), docs/jobs/steep_health-response-backwards-compatibility/implementation.md
  depends: TASK-1, TASK-2, TASK-3
  risk: low. Verification only. Both commands must exit 0 for the job to be done.

## Open points (not blocking; defaults chosen, flag if wrong)

1. **Test location.** The brief only asks for "a test that pins both
   shapes". `docs/TESTING.md` and STANDARDS 13.6 say client contract tests
   live in `tests/Unit/ClientContractTest.php`. That file does not exist yet:
   the separate backlog entry `client-contract-tests-1-0-15` creates it and
   names this job as its prerequisite. Default: create the file now with
   only the health test, which the later job extends. The `data.*` (envelope)
   assertions are not something 1.0.15 reads. If the reviewer prefers, they
   can go in a second `it()` in the same file, but they should not be dropped,
   because the brief asks for both shapes.
2. **STANDARDS wording.** The brief only asks for the 1.5 note. The stale
   lines at `:172-178` and `:568` are about the same route and would be
   wrong after this job, so TASK-3 includes them. Nothing else in
   STANDARDS.md changes (13.5's "Bad" example stays as history).
3. **Backlog entry.** `docs/backlog/health-response-backwards-compatibility.md`
   is the source of this job. Not touched here. Whether to delete it when
   the job lands is up to the manigot pipeline or the owner.
4. **Out of scope** (per brief): the frontend check in `solyto/app`, the
   `'1.0.7'` fallback in `config/app.php:40`, and the rest of the 1.0.15
   contract tests.
