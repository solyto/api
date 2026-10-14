# Verdict: Client minimum version (426)

id: believe
reviewer: @reviewer
date: 2026-10-14

## Review

Branch verified: `feature/believe_client-minimum-version-426`. Diff reviewed: `git diff main...HEAD`.
Code changes are limited to `config/app.php`, `app/Shared/Middleware/RecordClientVersion.php`, and the new
`app/Api/Users/Tests/ClientMinimumVersionTest.php`. Everything else is job docs. Nothing changed outside the scope of tasks.md, and
`bootstrap/app.php` and `routes/api.php` are untouched.

Checks I ran myself in the worktree:
- `composer analyse`: exit 0 ("No errors").
- `composer test` (full suite): exit 0, with 0 failures. The "warnings" are the missing `.env` (`file_get_contents`) noise, not test problems.
  The flaky `PasskeyTest` from implementation.md did not fail on this run.
- I ran `ClientMinimumVersionTest` and `ClientVersionHeaderTest` on their own, and all cases passed.

TASK-1: PASS
notes: `config/app.php` adds `'min_client_version' => env('MIN_CLIENT_VERSION')`. The default is `null`, so the check is off, and it has a comment block next to `version`.

TASK-2: PASS
notes: `RecordClientVersion::isBelowMinimum()` runs before `$next`. It only rejects when all of these are true: the minimum is a non-empty string, the header matches `PATTERN`, the route's `gatherMiddleware()` contains `auth:sanctum`, and `version_compare(..., '<')` is true. This extends the existing middleware rather than adding a second one, as the brief requires. The record-after-`$next` logic is unchanged. Because the `api` group is applied at route level, `$request->route()` has been resolved by this point, and the tests confirm it.

TASK-3: PASS
notes: The 426 goes through the envelope via `ApiResponse::error('Please update the app.', 426, meta: [...])`. The brief asked for `meta.min_client_version`, and the existing `HttpException` branch could not supply it, so returning it from the middleware is correct. This was the option tasks.md preferred.

TASK-4: PASS
notes: The tests cover every case in the brief: off, below (`1.0.9` < `1.0.10`, which proves the comparison is numeric), equal, above, missing header, `health`, and `auth/login`. There is also an extra case for an invalid header. The 426 case asserts the envelope, the message, and `meta.min_client_version`.

TASK-5: PASS
notes: implementation.md records both checks, and my own runs confirm exit 0 for both.

Non-blocking note for the owner: the authenticated `auth/*` routes (`logout`, `refresh`, `tokens`, ...) also return 426 below the minimum. This is tasks.md Open question 1. It follows the brief's literal rule "auth:sanctum group only" and its rationale ("connect and log in, and then see the 426"). If outdated builds should still be able to log out or refresh, that is a small follow-up.

## Security

Not run. I saw no concerns: the header is regex-validated before `version_compare`, and the 426 response contains only the configured minimum.

## Overall

APPROVED. No blockers.
