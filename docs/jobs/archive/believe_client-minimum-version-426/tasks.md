# Tasks: Client minimum version (426)

id: believe
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

Branch verified: the worktree HEAD is `refs/heads/feature/believe_client-minimum-version-426`, which matches `brief.md`.

## Context (what exists today)

- `app/Shared/Middleware/RecordClientVersion.php` is the middleware from `client-version-header`. It is added to the **`api` group** in `bootstrap/app.php:34` (`appendToGroup('api', ...)`). It does its work **after** `$next`, because the `api` group runs before the route's `auth:sanctum`.
- `routes/api.php:60-84` holds the public routes (`health`, `auth/login`, `auth/register`, ...). `routes/api.php:86` is the `auth:sanctum` group. Note that it **also contains `auth/*` routes**: `logout`, `logout-all`, `refresh`, `tokens`, `revoke-token`, `passkeys/*`.
- In `bootstrap/app.php:86-91`, the generic `HttpException` branch calls `ApiResponse::error($message, $status)` **without `meta`**. A plain `abort(426, ...)` would therefore give the right status and message, but **no `meta.min_client_version`**. The brief says the existing rendering is enough. It is not enough for the `meta` part.
- `ApiResponse::error(string $message, int $status, $errors = null, array $meta = [])` already supports `meta` (`app/Api/ApiResponse.php:36-57`).

## Task breakdown

TASK-1: Add `'min_client_version' => env('MIN_CLIENT_VERSION')` (default `null`) to `config/app.php`, with a short comment block next to `version`.
files: `config/app.php`
depends: none
risk: low. It is one additive config key, and `null` keeps the check off everywhere, including tests.

TASK-2: Extend `RecordClientVersion` to reject with a 426 **before** `$next`. This happens only when all of the following are true: the minimum is set, the header is present and matches the existing `PATTERN`, `version_compare($header, $min, '<')` is true, and the matched route carries `auth:sanctum` (e.g. `in_array('auth:sanctum', $request->route()?->gatherMiddleware() ?? [], true)`). The existing record-after-`$next` logic stays unchanged.
files: `app/Shared/Middleware/RecordClientVersion.php`
depends: TASK-1
risk: medium. The middleware sits on every API request, and the group-membership check plus the ordering with `auth:sanctum` are easy to get subtly wrong (see Open questions 1 and 2).

TASK-3: Make the 426 carry `meta.min_client_version` through the envelope. The smallest option is to return `ApiResponse::error('Please update the app.', 426, meta: ['min_client_version' => $min])` directly from the middleware, with no change to `bootstrap/app.php`. The alternative is to throw an `HttpException(426)` and extend the `HttpException` branch in `bootstrap/app.php` to add the meta. That alternative adds a special case to the central map, so prefer the first option unless the reviewer objects. STANDARDS 7.1 is satisfied in both cases because the body is built by `ApiResponse`.
files: `app/Shared/Middleware/RecordClientVersion.php` (preferred), or `bootstrap/app.php`
depends: TASK-2
risk: low. `ApiResponse::error` already accepts `meta`. The only risk is picking the option that touches the central exception map.

TASK-4: Add Pest feature tests for the brief's cases. Use `config(['app.min_client_version' => '1.0.16'])` and real bearer tokens, like `ClientVersionHeaderTest`. Cases:
- the minimum is `null` and an old header is sent: 200
- the header is below the minimum (`1.0.9` vs min `1.0.10`, to prove the comparison is numeric): 426 with `success: false`, `message` "Please update the app." and `meta.min_client_version`
- the header equals the minimum: 200
- the header is above the minimum: 200
- the header is missing: 200
- `GET health` with an old header: not 426
- `POST auth/login` with an old header: not 426

Also add one case showing that an invalid header (e.g. `abc`) is not rejected.
files: new `app/Api/Users/Tests/ClientMinimumVersionTest.php` (or extend `app/Api/Users/Tests/ClientVersionHeaderTest.php`)
depends: TASK-2, TASK-3
risk: low. These are test-only changes. The test must reset the config per test, which Pest/Laravel already does between tests.

TASK-5: Run `composer test` and `composer analyse` in the worktree, and record both exit codes in `implementation.md`.
files: `docs/jobs/believe_client-minimum-version-426/implementation.md`
depends: TASK-1 to TASK-4
risk: low. It only runs checks, but the full suite must be green, not just the new file.

## Open questions (flagged, not guessed)

1. **The authenticated `auth/*` routes.** The brief says the check "applies to the `auth:sanctum` group only" and that "`auth/*` stays reachable". `auth/logout`, `auth/refresh`, `auth/tokens`, `auth/revoke-token` and `auth/passkeys/*` sit **inside** the `auth:sanctum` group (`routes/api.php:87-100`). The brief's rationale ("can still connect and log in") and its test list (`health`, `auth/login`) only need the public routes. This plan therefore follows the literal rule: every route in the `auth:sanctum` group, including `auth/logout`, gets the 426. If the owner wants outdated builds to still be able to log out or refresh, TASK-2 needs an extra exemption for `api/v1/auth/*`. **@reviewer / owner, please confirm.**
2. **426 vs 401 for an unauthenticated outdated request.** With the check before `$next` (the `api` group runs before `auth:sanctum`), a request to a protected route with an old header and **no or invalid token** gets a 426, not a 401. That seems acceptable, since the app has to update either way, but it is a behaviour choice. Checking only after authentication would require changing middleware priority, which is out of proportion for this job.
3. **Invalid header values.** The brief does not say how they are handled. This plan treats a header that does not match the existing `PATTERN` like a missing header (allowed). That matches how `client-version-header` already ignores invalid values, and the Decision "a request without the header is always accepted".
4. **Not in the brief, so not planned:** an OpenAPI annotation for the 426 response, a `MIN_CLIENT_VERSION` entry in `.env.example`, and a STANDARDS/Decisions update. Add these only if the reviewer asks for them.
