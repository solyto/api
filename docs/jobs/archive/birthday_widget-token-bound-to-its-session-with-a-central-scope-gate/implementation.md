# Implementation: widget token bound to its session, with a central scope gate

id: birthday
developer: developer
date: 2026-10-29

**This touches authentication: flag it in review.**

## Summary

Restricted widget token (`abilities: ['widget']`) issued by `POST /v1/auth/widget-token`, bound to the issuing session via a self-referencing `parent_token_id` FK with `ON DELETE CASCADE` and a unique index. A single Sanctum callback in `AppServiceProvider` allows a token without `*` only on routes named `widgets.*`. Widget tokens are hidden from the session list and can't be revoked by id. Logout, refresh, logout-all, password change and revoke-token are unchanged; the cascade removes the widget token.

## Changes

TASK-1: `database/migrations/2026_10_29_130000_add_parent_token_id_to_personal_access_tokens.php` adds a nullable, unique `parent_token_id` FK to `personal_access_tokens.id` with `cascadeOnDelete()`. `down()` drops the FK, the index and the column. It ran only against in-memory SQLite in tests, not against a real database.
TASK-2: `AppServiceProvider::boot()` registers `Sanctum::authenticateAccessTokensUsing()`. The result is `$isValid && (token has '*' || request()->route()->named('widgets.*'))`, so the gate can only narrow access, never widen it.
TASK-3: Added the `widget-token` limiter (10/min, per user id, ip fallback) to `AppServiceProvider`.
TASK-4: `AuthService::createWidgetToken()` runs in one transaction. It deletes the session's previous widget token, then calls `createToken('widget-token', ['widget'], $parent->expires_at)` and sets `parent_token_id` with `forceFill` (the column isn't fillable on Sanctum's model, so I didn't add a custom model). It uses two typed exceptions in `app/Api/Users/Exceptions/`: `PersonalAccessTokenRequiredException` and `WidgetTokenConflictException`. The second wraps the `UniqueConstraintViolationException` from the losing concurrent request. A local `@var HasAbilities` lets the `instanceof` check pass PHPStan, because Sanctum's generic types the token as `PersonalAccessToken`.
TASK-5: Added `AuthController::widgetToken()` with OpenAPI annotations (`authWidgetToken`; 201/401/403/409/429). The route `POST auth/widget-token` is in the authenticated `auth` group with `throttle:widget-token` and has no name, which follows STANDARDS 1.4 and avoids `widgets.`.
TASK-6: `listTokens()` and `revokeToken()` filter on `whereNull('parent_token_id')`. A widget token id now gets 404.
TASK-7/8: New `app/Api/Users/Tests/WidgetTokenTest.php` (16 tests, real Bearer tokens, `forgetGuards()` between requests) covers:
- the gate: 401 on `users/me`, `refresh`, `widget-token`, `tokens`, and on an unnamed route; a normal token still works; the widget token is accepted on a temporary `widgets.probe` route
- the callback sees the resolved route name
- an expired parent's widget token is rejected
- the response shape and the parent's expiry
- one widget token per session, across two sessions
- 403 for a `TransientToken`
- 409 for a simulated concurrent insert
- the cascade on logout, revoke-token, logout-all, password change and refresh
- widget tokens are absent from the token list, and revoking one by id gives 404
Rework (verdict 1): `createWidgetToken()` also maps a concurrency error to the 409 `widget_token_conflict`. On InnoDB the losing request can deadlock (SQLSTATE 40001/1213) instead of hitting the unique index. It catches `QueryException` at the top level and `DeadlockException` when nested, and checks them with Laravel's `DetectsConcurrencyErrors`. Other query errors are rethrown. A new test throws a deadlock `QueryException` inside the transaction and asserts 409 and no widget token left behind.
TASK-9: `composer test` and `composer analyse` both exit 0.

Defaults I picked for the analyst's open points:
- **Cookie session (`TransientToken`):** 403 with `errors: ['personal_access_token_required']`.
- **Losing concurrent request (unique violation or deadlock):** 409 with `errors: ['widget_token_conflict']`. The client retries; there is no retry on the server.

## Known issues / follow-ups

- The self-referencing cascade on bulk deletes (`logoutAll`, `changePassword`, `UserService::destroy`) is only proven on SQLite. Please confirm it on MariaDB before deploying, including the `auth_tokens` count that `destroy` returns.
- Environment only, not caused by this job:
  - The worktree had no `vendor/` or `storage/` dirs. I ran `composer install` from the lock file (no new packages) and created the gitignored `storage/framework/*` dirs.
  - Every test prints a warning that `.env` is missing.
  - One run of the full suite had a failure in `ExportTest` (`FinanceWealthExportService` writes to `storage/app/public/user/...`). Two re-runs passed, so it looks flaky or tied to the environment and unrelated to this change.
- `.env.testing` shows as modified but git can't hash it (it is a special mount). I left it untouched and uncommitted.
