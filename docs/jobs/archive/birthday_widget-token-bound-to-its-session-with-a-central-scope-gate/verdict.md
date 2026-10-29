# Verdict: widget token bound to its session, with a central scope gate

id: birthday
reviewer: reviewer
date: 2026-10-29
round: 2 (re-review after verdict 1, NEEDS WORK)

**This touches authentication.** The brief asks for it to be flagged in review, so it is flagged here.

Verification on this branch:
- `composer analyse` exits 0.
- `composer test`: the first full run had 1 failure in `UserTest.php:274` ("VerificationToken Factory → creates a token expiring soon"). Neither that test nor the factory is touched by this branch. The factory uses `dateTimeBetween('now', '+1 hour')`, which can land on `now()`, so the test is flaky on its own. That test file passed 3 isolated runs, and a second full run exited 0 (1265 tests, only `.env` warnings).
- The diff against `main` matches `implementation.md`. Apart from job bookkeeping files, nothing outside the tasks changed.

## Review

TASK-1: PASS
notes: The migration adds a nullable, unique, self-referencing `parent_token_id` with `cascadeOnDelete()`. `down()` drops the FK, the unique index and the column. It is only proven on SQLite (see Security).

TASK-2: PASS
notes: `AppServiceProvider.php:48`. The gate returns `$isValid && (has '*' || route named 'widgets.*')`, so it can only narrow access. A test confirms the route is resolved when the callback runs.

TASK-3: PASS
notes: The `widget-token` limiter allows 10 per minute per user, with the IP as fallback.

TASK-4: PASS
notes: `AuthService.php:93-128`. Verdict 1's finding is fixed:
- `UniqueConstraintViolationException` is caught first.
- Then `QueryException|DeadlockException` is checked with Laravel's `DetectsConcurrencyErrors` and mapped to `WidgetTokenConflictException`. Any other error is rethrown.
- Both branches are reachable. At the top level, `DB::transaction` (1 attempt) rethrows the `QueryException`. When nested, Laravel converts it to `DeadlockException`. The new test covers the nested path because the test DB already wraps everything in a transaction.
- The test also asserts that the transaction rolls back and no widget token is left.

TASK-5: PASS
notes: The route `POST auth/widget-token` is unnamed, sits in the authenticated `auth` group and uses `throttle:widget-token`. It returns 201, and 403/409 come with error codes. The OpenAPI annotation matches the other auth endpoints.

TASK-6: PASS
notes: `listTokens()` and `revokeToken()` both filter on `whereNull('parent_token_id')`. Revoking a widget token id returns 404.

TASK-7: PASS
notes: The tests use real Bearer tokens and call `forgetGuards()` between requests. They cover:
- a widget token gets 401 on `users/me`, `refresh`, `widget-token`, `tokens` and on an unnamed route
- a normal token still works on those routes
- a temporary `widgets.probe` route accepts the widget token
- the route is resolved when the gate runs
- the widget token of an expired parent is rejected

TASK-8: PASS
notes: The tests cover:
- the response shape
- the widget token's expiry equals the parent's
- one widget token per session, across two sessions
- 403 for a cookie session
- 409 for both a unique violation and a deadlock
- the cascade on logout, revoke-token, logout-all, password change and refresh
- widget tokens absent from the list, and 404 when revoking one by id

TASK-9: PASS
notes: The reviewer re-ran the full suite and `composer analyse`, and both exit 0. The one failure seen along the way is the unrelated flaky factory test described above.

## Security

- The gate is a single callback and can only narrow access. Restricted tokens are denied by default on every route not named `widgets.*`, including the issuing route, so a widget token cannot mint another token or refresh.
- Deploy note, not a code blocker (tasks.md flags it too): confirm on MariaDB that the self-referencing cascade works on the bulk `DELETE … WHERE tokenable_id = ?`, and check the `auth_tokens` count that `UserService::destroy` returns. Per the hard rule, show the migration before running it.

## Overall

APPROVED
