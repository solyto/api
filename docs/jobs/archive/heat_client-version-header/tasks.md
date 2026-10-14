# Tasks: Client version header

id: heat
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

Branch verified: worktree HEAD is `refs/heads/feature/heat_client-version-header`,
which matches `brief.md`.

## Task breakdown

TASK-1: Add `X-Client-Version` to `allowed_headers` in `config/cors.php`, leaving `exposed_headers` empty.
  files: config/cors.php (line 27)
  depends: none
  risk: low. It is a one-line additive config change. It only widens what the preflight accepts, and it is required before any frontend build sends the header (STANDARDS 13.7).

TASK-2: Add an additive migration that creates a nullable `client_version` string column on `personal_access_tokens`. The migration must be shown, not run.
  files: database/migrations/2026_10_14_000001_add_client_version_to_personal_access_tokens.php (new; same shape as `2026_05_20_000001_add_link_to_todos.php`: `Schema::table`, `->nullable()`, `down()` drops the column)
  depends: none
  risk: low. One nullable column on the default (MariaDB) connection, not `pgsql`. It must be shown before it is run (hard rule), and tests pick it up through in-memory SQLite. Suggest a short length (e.g. `string('client_version', 32)`) and `->after('last_used_at')`.

TASK-3: Create the middleware `App\Shared\Middleware\RecordClientVersion`. It reads `X-Client-Version`, accepts only `major.minor.patch`, and writes the value to the current Sanctum token only when it changed. Missing or invalid values are ignored.
  files: app/Shared/Middleware/RecordClientVersion.php (new; `app/Shared/Middleware/` is a new sub-folder, which the brief approves as "under `app/Shared/`")
  depends: TASK-2
  risk: medium. Three things need care:
    (a) **Ordering:** a middleware appended to the `api` group runs *before* the route-level `auth:sanctum`. Custom middleware isn't in Laravel's priority list, so it isn't re-sorted after `Authenticate`. Before `$next($request)` there is no user yet. The simplest fix is to do the recording *after* `$next($request)` returns, once the guard has resolved the user. The alternative is to adjust the middleware priority. The developer should pick one and test it.
    (b) **Only real tokens:** write only when `$request->user()?->currentAccessToken()` is an instance of `Laravel\Sanctum\PersonalAccessToken`, not a `TransientToken`. Public routes (`health`, `auth/*`) have no user and are skipped.
    (c) **Validation:** use a strict regex such as `/^\d{1,5}\.\d{1,5}\.\d{1,5}$/` (anchored, length-bounded so it fits the column). Never reject or throw. If the value equals the stored one, do no write.
  `client_version` is not fillable on Sanctum's model, so use `forceFill([...])->save()`. Don't log the header value. No comments beyond a "why" if needed (STANDARDS 10.1).

TASK-4: Register the middleware on the `api` group only, in `bootstrap/app.php` → `withMiddleware` (e.g. `$middleware->appendToGroup('api', RecordClientVersion::class)` or `$middleware->api(append: [...])`).
  files: bootstrap/app.php
  depends: TASK-3
  risk: low. It is one line. It must not use global `append`/`prepend` or the `web` group, so DAV and the Telegram webhook (`routes/web.php`) are untouched. No DAV route change.

TASK-5: Add Pest tests covering every case in the brief.
  files: app/Api/Users/Tests/ClientVersionHeaderTest.php (new; see open question 1)
  depends: TASK-1, TASK-2, TASK-3, TASK-4
  risk: medium. The tests must use a **real** token (`$user->createToken('auth-token-web')->plainTextToken` + `Authorization: Bearer`), not `Sanctum::actingAs`, which attaches a mock token and would not exercise the database write. Cases:
    - valid header (e.g. `1.0.16`) on an authenticated `v1` request: the token row gets `client_version = '1.0.16'`
    - a second request with a different valid version updates the value
    - missing header: request succeeds, `client_version` stays `null` or unchanged
    - garbage header (e.g. `abc`, `1.0`, `1.0.16-beta`, an over-long value): request succeeds, value unchanged
    - unauthenticated public route (`v1/health`) with the header: succeeds, no error
    - CORS preflight: `OPTIONS /api/v1/...` with `Origin` set to an allowed origin (e.g. `http://localhost:5173` or a `*.solyto.app` origin), `Access-Control-Request-Method: GET` and `Access-Control-Request-Headers: X-Client-Version`. The response is a success, and `Access-Control-Allow-Headers` contains `x-client-version` (case-insensitive)

TASK-6: Run `composer test` and `composer analyse` in the worktree. Both must exit 0.
  files: none
  depends: TASK-1 to TASK-5
  risk: low. Verification only. PHPStan may need `instanceof PersonalAccessToken` narrowing in TASK-3.

## Open questions / flags

1. **Test location is not specified.** `docs/TESTING.md` says `tests/Feature/` must stay empty and HTTP tests go next to their module. `app/Shared` has no `Tests/` folder, and only `app/Api/*/Tests` gets `RefreshDatabase` automatically. I propose `app/Api/Users/Tests/` because tokens are issued in `Users/AuthService`. If the owner prefers a new `app/Shared/Tests/` folder, that needs Pest/phpunit wiring and is a bigger change. I don't treat this as blocking.
2. **New folder `app/Shared/Middleware/`.** STANDARDS 12.1 lists the `app/Shared` sub-folders, and `Middleware` isn't one of them. The brief approves the middleware under `app/Shared/`, so the folder name is the only open choice. STANDARDS 12.1 is not updated in this job (out of scope unless the owner asks).
3. **Migration:** shown, not run, per the hard rule. Tests use in-memory SQLite and run it automatically.
4. **No DAV / `pgsql` impact:** the middleware is on the `api` group only. `personal_access_tokens` lives on the default connection.
