# Verdict: Rate-limit responses say what happened and when to retry

id: piece
reviewer: reviewer
date: 2026-10-29

## Review

TASK-1: PASS
notes: `bootstrap/app.php:83-87` matches the brief exactly: `['too_many_requests']`, `meta.retry_after` from `Retry-After` (or null), headers forwarded. `grep -n "too_many_requests" bootstrap/app.php` → 1 line.

TASK-2: PASS
notes: `ExportController.php:29-43` uses `latest()->first()`, so the wait is computed from the newest export. `retry_after` = `ceil(now()->diffInSeconds(created_at + 1 day))`, clamped to at least 1. Carbon 3 `diffInSeconds` is signed, and the target is in the future, so the value is positive. The condition and status are unchanged.

TASK-3: PASS
notes: `AuthTest.php:187-200`: 5×401, then 429 with `errors == ['too_many_requests']`, an int `meta.retry_after` > 0, and a `Retry-After` header. Using a unique email instead of `RateLimiter::clear` is a reasonable deviation, and the reason is documented in implementation.md.

TASK-4: PASS
notes: `ExportTest.php:46-62`: the prior export is created 3h in the past, then the test asserts `export_rate_limited` and `retry_after` ∈ [21h−5s, 21h]. It passed in 3 repeated filtered runs.

TASK-5: PASS
notes: `ErrorResponse` has an optional, nullable `meta.retry_after`. The 429 descriptions in `AuthController` and `UserController` are updated. `ExportController` was correctly left alone.

TASK-6: PASS
notes: Reviewer re-ran both in the worktree. `composer test` exited 0 (1241 warnings, from the missing `.env`, 0 failures). `composer analyse` exited 0 ("No errors").

Scope: the diff contains only the files listed in tasks.md plus job docs. No unrelated changes. The `.env.testing` modification is an uncommitted mount artefact and is not in the diff.

## Security

none (not run). The change only adds fields and headers to existing 429s and exposes no secrets.

## Overall

APPROVED

No blockers.
