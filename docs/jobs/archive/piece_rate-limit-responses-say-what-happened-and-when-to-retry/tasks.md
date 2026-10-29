# Tasks: Rate-limit responses say what happened and when to retry

id: piece
analyst: analyst
date: 2026-10-29

<!-- Produced by @analyst from brief.md. -->

## Context checked

- Branch verified: `feature/piece_rate-limit-responses-say-what-happened-and-when-to-retry`.
- `bootstrap/app.php:83-85` is the throttle handler (brief says 82-84; one line off).
- `ApiResponse::error(string $message, int $status, $errors = null, array $meta = [])`
  already supports `errors` and `meta`, so no change to `ApiResponse` is needed.
- `ExportController.php:29-38`: the recent-export check uses `->exists()`; to
  compute `retry_after` it must fetch the newest export's `created_at` instead.
- Throttled routes: `routes/api.php:79-86,110`; `auth-login` = 5/min by email or
  IP (`AppServiceProvider.php:44-46`).
- OpenAPI: 429 is documented in `AuthController.php:391` and
  `UserController.php:155`, both referencing `#/components/schemas/ErrorResponse`
  (`app/OpenApi/OpenApiSpec.php:33-40`), which has no `meta` property.
  `ExportController` has no OpenAPI annotations at all.
- No user-facing string changes (messages stay as-is), so no translator hand-off.

## Task breakdown

TASK-1: In the `ThrottleRequestsException` branch of the central exception map, return the 429 with `errors: ['too_many_requests']`, `meta.retry_after` (int seconds from the exception's `Retry-After` header, or null), and forward the exception's headers (`Retry-After`, `X-RateLimit-*`) via `->withHeaders($e->getHeaders())`, exactly as in the brief.
  files: bootstrap/app.php
  depends: none
  risk: low — additive fields on an existing 429 (rule 13.2); message and status unchanged.

TASK-2: In `ExportController::store`, replace the `exists()` check with a query for the newest export in the last 24h and return the 429 with `errors: ['export_rate_limited']` and `meta.retry_after` = seconds until that export's `created_at` is 24h old (int, at least 1).
  files: app/Api/Export/Controllers/ExportController.php
  depends: none
  risk: low — same condition and status; only the payload grows. Make sure to use the newest (`latest()`) row so the wait time isn't overstated.

TASK-3: Add a test that posts to `/api/v1/auth/login` 6 times (clear the `auth-login` limiter first, as `AccountDeletionTest` does) and asserts the 6th is 429 with `errors == ['too_many_requests']`, integer `meta.retry_after` > 0, and a `Retry-After` header.
  files: app/Api/Users/Tests/AuthTest.php
  depends: TASK-1
  risk: low — in-memory test; limiter key is per email/IP, so clear it to avoid cross-test bleed.

TASK-4: Extend the existing "limits exports to once per day" test (or add one next to it) to assert `errors == ['export_rate_limited']` and `meta.retry_after` close to the remaining time (e.g. create the prior export at a known time with `travelTo`/explicit `created_at`, assert within a small tolerance of the expected seconds).
  files: app/Api/Export/Tests/ExportTest.php
  depends: TASK-2
  risk: low — use frozen time to keep the assertion deterministic.

TASK-5: Document the 429 shape in OpenAPI: add an optional `meta` object (with nullable integer `retry_after`) to the `ErrorResponse` schema, and mention `too_many_requests` / `retry_after` in the existing 429 descriptions in `AuthController` and `UserController`. Do not add new annotations to `ExportController` (none exist; out of scope).
  files: app/OpenApi/OpenApiSpec.php, app/Api/Users/Controllers/AuthController.php, app/Api/Users/Controllers/UserController.php
  depends: TASK-1
  risk: low — annotation-only; `ErrorResponse` is shared, so keep `meta` optional/nullable so other error responses stay accurate (the 426 already sends `meta.min_client_version`).

TASK-6: Run `composer test` and `composer analyse`; both must exit 0. Confirm `grep -n "too_many_requests" bootstrap/app.php` returns exactly 1 line.
  files: none
  depends: TASK-1..TASK-5
  risk: low — verification only.

## Unclear / for the developer to note

- `ExportController` has no OpenAPI block, so brief step 3 ("update the OpenAPI
  description of 429 if one exists") only applies to the auth/user endpoints.
- `config/cors.php` `exposed_headers` stays `[]` per the brief (header only
  matters to non-browser clients); do not change CORS.
