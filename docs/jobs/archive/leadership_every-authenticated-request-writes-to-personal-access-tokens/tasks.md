# Tasks: Every authenticated request writes to `personal_access_tokens`

id: leadership
analyst: analyst
date: 2026-11-10

## Scope notes

- Branch verified: worktree HEAD is `feature/leadership_every-authenticated-request-writes-to-personal-access-tokens`, which matches `brief.md`.
- We use the middleware variant from brief §4. No `PersonalAccessToken` subclass, no package, no migration.
- **Keep the name `RecordClientVersion`** (brief §4.3 leaves this to the owner). That way `bootstrap/app.php` stays untouched. Renaming is out of scope for this job.
- `auth:sanctum` is only used in `routes/api.php` (the `v1` group), and `RecordClientVersion` is appended to the `api` group (`bootstrap/app.php:34`). So turning off Sanctum's own tracking loses no coverage. No web, DAV or bot route authenticates via Sanctum.
- Threshold: 5 minutes, as a private class constant in the middleware. No new config key (none requested).
- `last_used_at` must also be written when **no** `X-Client-Version` header is sent. The current early return on `$version === null` has to go, because old builds (≤ 1.0.15) and widgets send no header.

## Task breakdown

TASK-1: Turn off Sanctum's per-request `last_used_at` write by adding `'last_used_at' => false` to `config/sanctum.php`.
  files: config/sanctum.php
  depends: none
  risk: low. One config key. Sanctum's provider reads it (`SanctumServiceProvider.php:112`), but without TASK-2 `last_used_at` would stop updating, so the two must ship together.

TASK-2: Change `RecordClientVersion::handle` (the part after `$next`) to build a single update array. It gets `last_used_at => now()` when the token's `last_used_at` is null or older than 5 minutes, and `client_version` when a valid header differs from the stored value. It then runs at most one `PersonalAccessToken::whereKey(...)->update([...])`.
  files: app/Shared/Middleware/RecordClientVersion.php
  depends: TASK-1
  risk: medium. It runs on every API request. It must keep skipping non-`PersonalAccessToken` tokens and null users (public auth routes). It must keep the query update instead of `save()` so logout/refresh don't re-insert the token. It must handle a missing header without skipping the timestamp. The existing comment about the `$next` ordering should stay accurate.

TASK-3: Add Pest tests for the throttled timestamp in `app/Api/Users/Tests/` (a new file such as `TokenLastUsedTest.php`, following docs/TESTING.md):
  (a) a first request sets a null `last_used_at`;
  (b) two requests within 5 minutes cause only one `last_used_at` write (the second one leaves the value unchanged; use `travel()`/frozen time);
  (c) a request after more than 5 minutes updates it;
  (d) a request without `X-Client-Version` still updates a stale `last_used_at`;
  (e) a version change plus a stale timestamp produce exactly one `UPDATE` on `personal_access_tokens` (count it with `DB::listen`/query log);
  (f) logout/refresh with a stale timestamp still don't recreate the token.
  Use `$this->app['auth']->forgetGuards()` between requests, as the existing test does.
  files: app/Api/Users/Tests/TokenLastUsedTest.php (new); possibly app/Api/Users/Tests/ClientVersionHeaderTest.php (only if an existing case needs adjusting)
  depends: TASK-2
  risk: low. Test-only. Comparing timestamps on SQLite needs care: assert with second precision or with frozen time.

TASK-4: Run `composer test` and `composer analyse`. Both must exit 0. Confirm that `AuthService::listTokens` and `StatisticsService::overview` tests (if any) still pass, since they read `last_used_at`.
  files: none (verification)
  depends: TASK-1, TASK-2, TASK-3
  risk: low. Verification only. A failure most likely points to a test that relied on Sanctum setting `last_used_at` per request (the grep found none).

## Out of scope

- Renaming the middleware (owner call, brief §4.3).
- Measuring latency on MariaDB (brief §5).
- The per-request `select * from users` (brief §5).
