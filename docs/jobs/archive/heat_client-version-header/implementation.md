# Implementation: Client version header

id: heat
developer: @developer
date: 2026-10-14

## Summary

The API now accepts the `X-Client-Version` header in CORS preflights. When an authenticated `api` request carries a valid `major.minor.patch` version, the API records it on that request's Sanctum token (`personal_access_tokens.client_version`). It only writes when the value changed. A missing or invalid header is ignored and never rejected. The middleware runs on the `api` group only, so DAV and the Telegram webhook (`routes/web.php`) are untouched.

## Changes

TASK-1: `config/cors.php`: added `X-Client-Version` to `allowed_headers`. `exposed_headers` stays empty.

TASK-2: `database/migrations/2026_10_14_000001_add_client_version_to_personal_access_tokens.php` (new) adds `string('client_version', 32)->nullable()->after('last_used_at')`, and `down()` drops the column. It runs on the default connection, not `pgsql`. **The migration has not been run against any real database.** Only the in-memory SQLite test DB ran it. Here it is, for review before `php artisan migrate`:

```php
Schema::table('personal_access_tokens', function (Blueprint $table) {
    $table->string('client_version', 32)->nullable()->after('last_used_at');
});
// down(): $table->dropColumn('client_version');
```

TASK-3: `app/Shared/Middleware/RecordClientVersion.php` (new folder and class). For ordering, the middleware records the version *after* `$next($request)`. The `api` group middleware runs before the route-level `auth:sanctum`, so the user and token are only known after the route has run. The middleware writes only when:
- the header matches `/^\d{1,5}\.\d{1,5}\.\d{1,5}$/` (anchored and length-bounded, at most 17 chars, so it fits the 32-char column)
- `currentAccessToken()` is a real `PersonalAccessToken` (not a `TransientToken`)
- the value differs from the stored one

It writes with a query update scoped by id (`PersonalAccessToken::whereKey($token->getKey())->update([...])`). **Rework after review:** the first version used `forceFill()->save()`. On `auth/logout` and `auth/refresh` the token instance is deleted during the request, and `save()` on a deleted model re-INSERTed the token, which silently undid the revocation. A query update can't insert, so a deleted token stays deleted. The header value is never logged.

TASK-4: `bootstrap/app.php`: `$middleware->appendToGroup('api', RecordClientVersion::class)` inside `withMiddleware`, on the `api` group only.

TASK-5: `app/Api/Users/Tests/ClientVersionHeaderTest.php` (new). Requests use real bearer tokens (`createToken`), not `Sanctum::actingAs`. It covers:
- a valid header being recorded
- a later request with a different version updating the value
- a missing header leaving the value unchanged
- invalid headers (`abc`, `1.0`, `1.0.16-beta`, `v1.0.16`, trailing space, over-long segment, 40-char string) being ignored while the request still returns 200
- a header on public `v1/health` succeeding with nothing written
- `auth/logout` and `auth/refresh` with a valid header (rework regression tests): the old token row is gone and the old plain token gets 401. Both tests fail against the previous `save()` version and pass with the fix
- a CORS preflight with `Access-Control-Request-Headers: X-Client-Version` from a `*.solyto.app` origin being allowed, with `x-client-version` in `Access-Control-Allow-Headers`

TASK-6: After the rework, `composer test` exits 0 (791 tests, 2236 assertions). `composer analyse` exits 0 (`[OK] No errors`).

## Known issues / follow-ups

- **Environment setup:** the worktree had no `vendor/` and no `storage/framework/*`. I ran `composer install` (from the existing lock file, no new packages) and created the ignored `storage/framework/{views,cache,sessions,testing}` directories so the suite could run. There is no `.env`, so every test emits a `file_get_contents(.env)` warning. This affects existing tests too and isn't caused by this job.
- **One unexplained failing run:** the very first `composer test` run, right after the environment setup, reported 2 failed tests. I didn't capture which tests they were. Four later full runs (2× `php artisan test`, 2× `composer test`) all passed with 0 failures. This is probably a first-run or time-dependent flake, but I couldn't confirm the cause.
- **STANDARDS 12.1** lists the `app/Shared` sub-folders, and the new `Middleware` folder isn't in that list. I left the doc unchanged (out of scope).
- **Test location:** the tests are in `app/Api/Users/Tests/`, following the analyst's proposal (open question 1).
- **`.env.testing`** shows as modified in `git status`. In this sandbox it's a character device (bind mount), not a file I touched. I left it alone and didn't commit it.
