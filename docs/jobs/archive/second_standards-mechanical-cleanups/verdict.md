# Verdict: Standards: mechanical cleanups

id: second
reviewer: @reviewer
date: 2026-10-13 (round 2)

Branch checked: `feature/second_standards-mechanical-cleanups` (matches brief).
Base: `main` (from `.manigot/manigot.json`). Diff reviewed: `git diff main...HEAD`,
and `git diff 4df8f25..HEAD` for the round-2 changes. Every changed file is
accounted for in `implementation.md`. The `chore: commit all` commits only touch
`tokens.json`.

**Verification I ran myself:** `php artisan test` → 714 tests, 1903
assertions, **0 failures**. The 714 "warnings" are all
`file_get_contents(.env)`, because the worktree has no `.env`. The test count
matches the `main` baseline that the developer reports.

## Review

TASK-1: PASS
notes: Same as round 1. `grep -rn "env(" routes` → 0.

TASK-2: PASS
notes: The duplicate-operationId check (`uniq -d`) is empty.

TASK-3: PASS
notes: `grep -rn '@OA\\Property(property="success"' app/Api/*/Controllers` → 0. `OpenApiSpecTest` passes.

TASK-4: PASS
notes: This was decided as OQ-1. There are 268 operations and all paths start with `/`. `path="/api/` and `path="/v1/` → 0 in every controller. `@OA\Server(url="/api/v1")` is unchanged. In `tests/Unit/OpenApiSpecTest.php`, the prefix loop is replaced by an assertion on the server URL plus a plain `'api/v1/'.ltrim($path,'/')`, so a leftover prefix now fails the test. The round-2 controller diff contains only `path=` and 422 schema lines. `STANDARDS.md` 2.1, Decisions and Open questions are updated to match.

TASK-5: PASS
notes: The owner reversed OQ-2 and decided to keep the schema, so the brief's original check (→ 0) is superseded. `ValidationErrorResponse` is still defined in `OpenApiSpec.php:55`. The only 422s that still reference `ErrorResponse` are `FeedController.php:274,328`. Those are hand-raised "Feed URL doesn't seem valid" errors with no field `errors`, so keeping them is correct. `STANDARDS.md` 2.3/2.4 and the backlog entry are reworded to match.

TASK-6: PASS
notes: Unchanged from round 1.

TASK-7: PASS
notes: Unchanged from round 1.

TASK-8: PASS
notes: This follows owner option (a). `tests/Pest.php` now has `uses(RefreshDatabase::class)->in('../app/Api')`. The dead `app/Api/Pest.php`, `tests/Unit/Pest.php` and `tests/Feature/Pest.php` are deleted. `grep -rl 'uses(RefreshDatabase::class)' app/Api/*/Tests | wc -l` → 0. `app/Bots/Tests/SolytoBotTest.php` and `app/Dav/Tests/DavServiceTest.php` still have their own `uses()`. The suite passes, which shows the new binding works. `TESTING.md` and `STANDARDS.md` 12.9 are corrected.

TASK-9: PASS
notes: Root `AGENTS.md` and root `implementation.md` are deleted. The owner descoped `docs/CLAUDE.md`.

TASK-10: PASS
notes: `composer.json` now has `"name": "solyto/api"`, the project-context description, and `"license": "AGPL-3.0-only"`.

TASK-11: PASS
notes: The constraints are `^4.4` and `^4.0`, and `composer.lock` is unchanged, as the brief asks. The stale lock hash is documented.

TASK-12: PASS
notes: Only `bootstrap/cache/.gitkeep` is still tracked, and `.gitignore` has `bootstrap/cache/*.php`.

TASK-13: PASS
notes: Unchanged from round 1. DAV routes are untouched.

TASK-14: PASS
notes: Unchanged from round 1.

TASK-15: PASS
notes: All 37 models have `scopeForUser(Builder $query, string $userId)` and import the Eloquent `Builder`, so the brief's check gives a single signature. `User` uses `HasUuids` with a string `keyType`. Every scope caller (`IntegrationGateway`, the services, `TodoTest`) passes `$user->id`. The `->forUser($user)` calls in tests are factory states, not this scope.

TASK-16: PASS
notes: Unchanged from round 1.

TASK-17: PASS
notes: Unchanged from round 1. The 5 unlisted `Log::info` hits are documented as a follow-up, as tasks.md says.

## Security

I saw nothing security-relevant in the diff and no secrets were added. `.env.testing` is modified in the worktree but is **not** committed.

## Overall

APPROVED

No blockers. All round-1 blockers are resolved: the suite runs and passes, the owner decisions are recorded in `brief.md` and implemented, and `docs/CLAUDE.md` is descoped.
