# Tasks: widget token bound to its session, with a central scope gate

id: birthday
analyst: analyst
date: 2026-10-29

Branch verified: worktree HEAD is
`feature/birthday_widget-token-bound-to-its-session-with-a-central-scope-gate`.

**This touches authentication: flag it in review (brief, Notes).**

## Context found in the code

- Sanctum v4.3.3, default `Laravel\Sanctum\PersonalAccessToken` model (no
  custom model, no `usePersonalAccessTokenModel`). Its `$fillable` does not
  include `parent_token_id`, so the developer must set it with
  `forceFill()` (or equivalent), not via `createToken()` arguments. Do not
  add a custom token model unless that turns out to be unavoidable — ask first.
- Every token created today has abilities `['*']` (`AuthService::createToken`,
  `PasskeyService:203` via it, `sanctumToken()` in `tests/Pest.php`), so the
  gate does not lock out any existing session.
- Sanctum's guard computes `$isValid` (including the `expires_at` check)
  before calling the `authenticateAccessTokensUsing` callback; the callback
  must return `$isValid && …`, never widen it.
- `$this->actingAs($user, 'sanctum')` bypasses the guard and the callback (and
  sets a mock token that *is* a `PersonalAccessToken` instance). Every gate,
  issuing and cascade test must authenticate with a real `Bearer` token
  (`withHeader('Authorization', 'Bearer …')`). When one test sends requests
  with different tokens, reset cached guards between requests
  (`app('auth')->forgetGuards()`), or the first user sticks.
- Tokens are deleted today by: `AuthService::logout/logoutAll/refreshToken/
  revokeToken`, `UserService::changePassword` and `destroy`,
  `PasswordService::resetPassword`, `Commands/ChangePassword`. All delete the
  parent row, so the FK cascade covers widget tokens without code changes.
- Tests run on SQLite with `foreign_key_constraints => true`
  (`tests/TestCase.php:46`), so the cascade is exercised in tests.
- `tests/Unit/MigrationCompatibilityTest.php` scans new migrations' `up()`;
  an additive nullable column + FK + unique index triggers none of its
  patterns, so no allowlist entry is needed.
- STANDARDS 1.4: no route gets a name without a caller. The brief only says
  the issuing route's name must not start with `widgets.`; leaving it
  **unnamed** satisfies both.

## Unclear points (developer: pick the suggested default, note it in implementation.md)

- **Status/code when the current token is a `TransientToken`** (cookie auth):
  the brief only says "an error with a code". Suggested: 403 with
  `errors: ['personal_access_token_required']`.
- **Status/code for the losing concurrent request** (unique index violation):
  the brief only says "no 500". Suggested: catch
  `UniqueConstraintViolationException` in the service (STANDARDS 4.8) and
  return 409 with `errors: ['widget_token_conflict']`; the app retries.
  Retrying once inside the request is the alternative.
- **MariaDB vs SQLite:** a self-referencing FK with `ON DELETE CASCADE` hit by
  a bulk `DELETE … WHERE tokenable_id = ?` (`logoutAll`, `changePassword`,
  `destroy`) is fine on InnoDB as far as known, but tests only prove it on
  SQLite. Reviewer should confirm on a MariaDB instance before deploy.
  `UserService::destroy` returns `auth_tokens` = affected-rows count; whether
  MariaDB counts cascaded rows may differ from SQLite — check `DeleteUserTest`
  assertions still hold.

## Task breakdown

TASK-1: Add a migration that adds nullable `parent_token_id` to `personal_access_tokens` with a self-referencing FK (`cascadeOnDelete()`) and a unique index; `down()` drops FK, index and column.
  files: database/migrations/2026_10_29_000001_add_parent_token_id_to_personal_access_tokens.php (new)
  depends: none
  risk: medium — schema change on the auth table; must be shown before running (hard rule) and the self-FK cascade is only verified on SQLite in tests.

TASK-2: Register the central scope gate with `Sanctum::authenticateAccessTokensUsing()` in `AppServiceProvider::boot()`: a token without the `*` ability is valid only if `$isValid` and the current route's name matches `widgets.*`; tokens with `*` keep `$isValid` unchanged.
  files: app/Shared/Providers/AppServiceProvider.php
  depends: none
  risk: high — runs on every bearer-authenticated request; a mistake either locks out all users or lets restricted tokens through.

TASK-3: Add a `widget-token` rate limiter (per user id, 10/minute) next to the other limiters in `AppServiceProvider::boot()`.
  files: app/Shared/Providers/AppServiceProvider.php
  depends: none
  risk: low — additive named limiter, same pattern as `account-deletion`.

TASK-4: Add `AuthService::createWidgetToken(User $user)` that, in one DB transaction, deletes the current token's existing widget token and creates `widget-token` with abilities `['widget']`, `parent_token_id` = current token id, `expires_at` = current token's `expires_at`; it rejects a non-`PersonalAccessToken` current token with a typed/coded error and handles the unique-index violation from a concurrent request without a 500; returns `{ token, token_type, token_expires_at }`.
  files: app/Api/Users/Services/AuthService.php (possibly a small typed exception in app/Api/Users/Exceptions/ if that is how the controller maps the two error cases, matching `EmailUnverifiedException`)
  depends: TASK-1
  risk: medium — token issuance and race handling; `parent_token_id` is not fillable on Sanctum's model.

TASK-5: Add `AuthController::widgetToken()` and the unnamed route `POST auth/widget-token` with `throttle:widget-token` in the authenticated `auth` group, returning 201 via `ApiResponse::success`, mapping the two error cases from TASK-4, with OpenAPI annotations like the other auth endpoints (`operationId="authWidgetToken"`, `security={{"sanctum": {}}}`, 201/401/403/409/429 responses as decided).
  files: app/Api/Users/Controllers/AuthController.php, routes/api.php
  depends: TASK-3, TASK-4
  risk: low — follows the existing auth endpoint pattern.

TASK-6: Exclude widget tokens from the session list: `listTokens()` adds `whereNull('parent_token_id')`, and `revokeToken()` looks up only tokens with `parent_token_id` null so a widget token id returns not found (404).
  files: app/Api/Users/Services/AuthService.php
  depends: TASK-1
  risk: low — two query filters; additive for old clients (STANDARDS 13.2).

TASK-7: Gate tests with real Bearer tokens: a widget token gets 401 on `GET /users/me`, `POST /auth/refresh`, `POST /auth/widget-token`, `GET /auth/tokens`; a normal token still works on them; a widget token is accepted on a temporary test route named `widgets.*` registered inside the test (under `auth:sanctum`); a test proves the route is resolved (named) when the callback runs; an expired widget token is rejected.
  files: app/Api/Users/Tests/WidgetTokenTest.php (new)
  depends: TASK-2, TASK-5
  risk: low — tests only; watch for the `actingAs` bypass and guard caching noted above.

TASK-8: Issuing and lifecycle tests: 201 + response shape; issuing twice from one session leaves one widget token; two sessions leave one each; widget expiry equals parent expiry; cookie/TransientToken request gets the error code; the race path returns the chosen non-500 response; widget token is deleted after logout, revoke-token (of the parent, from another session), logout-all, password change (`PUT /users/change-password`) and refresh; widget tokens are absent from `GET /auth/tokens` and `revoke-token` with a widget token id returns 404.
  files: app/Api/Users/Tests/WidgetTokenTest.php
  depends: TASK-4, TASK-5, TASK-6
  risk: low — tests only; the race case may need to be provoked by pre-inserting a conflicting row or by testing the service catch path directly.

TASK-9: Run `composer test` and `composer analyse` and make both exit 0 (full suite, incl. `MigrationCompatibilityTest`, existing `AuthTest`, `DeleteUserTest`, `AccountDeletionTest`).
  files: none expected
  depends: TASK-1 … TASK-8
  risk: low — verification step.

## Out of scope (from the brief)

- Any real `widgets.*` route (job 3).
- Scheduling `sanctum:prune-expired`.
- No changes to `refreshToken()`, `logout()`, `logoutAll()`, `changePassword()`, `destroy()`.
