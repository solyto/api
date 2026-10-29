## Summary

Every 429 now carries a machine-readable code and the wait time in `meta.retry_after` (seconds). The status code and message stay the same, and only fields were added (rule 13.2). Throttle 429s also forward the exception's `Retry-After` / `X-RateLimit-*` headers.

## Changes

TASK-1: `bootstrap/app.php`: the `ThrottleRequestsException` handler returns `errors: ['too_many_requests']`, `meta.retry_after` (int taken from the `Retry-After` header, or null), and the exception's headers. This is the code from the brief.
TASK-2: `app/Api/Export/Controllers/ExportController.php`: the 24h check now loads the newest export in the window (`latest()->first()`) instead of only checking `exists()`. The 429 adds `errors: ['export_rate_limited']` and `meta.retry_after`, which is the seconds until that export is 24h old (at least 1).
TASK-3: `app/Api/Users/Tests/AuthTest.php`: 5 failed logins return 401, and the 6th returns 429 with `too_many_requests`, an integer `meta.retry_after` > 0 and a `Retry-After` header. It uses a unique email instead of `RateLimiter::clear('auth-login')`. The named limiter's cache key is hashed, so clearing by that name would not reach it, and the test cache starts empty in every test anyway.
TASK-4: `app/Api/Export/Tests/ExportTest.php`: the once-per-day test creates the earlier export 3h in the past and asserts `export_rate_limited` and `retry_after` within 5s of 21h.
TASK-5: `app/OpenApi/OpenApiSpec.php`: `ErrorResponse` gets an optional, nullable `meta` object with a nullable integer `retry_after`. The existing 429 descriptions in `AuthController` and `UserController` now mention the code and `meta.retry_after`. `ExportController` has no annotations and was left alone.
TASK-6: `composer test` exits 0 (1241 warnings, all from the missing `/workspace/.env` in this worktree) and `composer analyse` exits 0. `grep -n "too_many_requests" bootstrap/app.php` returns 1 line.

## Known issues / follow-ups

- The first `composer test` run reported 2 failed tests. The output was not saved, so I don't know which ones. Three more full runs all passed, so these look like flaky tests, but that is unconfirmed.
- `php artisan l5-swagger:generate` fails with "Required @OA\Info() not found" in this environment, even though `@OA\Info` exists in `OpenApiSpec.php`. The cause is probably swagger-php 6.16 not reading docblock annotations, though I haven't confirmed that. Because of this, the annotation changes could not be checked by generating the spec. I did not fix it here.
- `ErrorResponse.errors` is documented as `type="object"`, but these 429s (and other coded errors) send a list of strings. I left it unchanged because it's out of scope.
- `.env.testing` shows as modified in the worktree. It is a mount, not a regular file (git cannot hash it). I didn't touch it and it was not committed.
- To run the tests locally I ran `composer install` from the lock file and created the gitignored `storage/framework/*` and `storage/app/*` directories.
