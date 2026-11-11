# Verdict: Every authenticated request writes to `personal_access_tokens`

id: leadership
reviewer: reviewer
date: 2026-11-10

## Review

Reviewed `git diff main...HEAD` on `feature/leadership_every-authenticated-request-writes-to-personal-access-tokens`. Changed source files: `config/sanctum.php`, `app/Shared/Middleware/RecordClientVersion.php` and `app/Api/Users/Tests/TokenLastUsedTest.php`. These match `implementation.md`, and no file outside the task list changed.

TASK-1: PASS
notes: `config/sanctum.php` adds `'last_used_at' => false`, which `SanctumServiceProvider` reads, so Sanctum's guard stops writing on every request. `auth:sanctum` is only used on the `routes/api.php` routes, and those run through the `api` group that holds `RecordClientVersion`, so no route loses tracking.

TASK-2: PASS
notes: `RecordClientVersion.php:27-51` builds one change array and runs at most one `whereKey()->update()`.
- `last_used_at` is written when it is null or older than 5 minutes (a private const).
- `client_version` is written only when a valid header differs from the stored value.
- A missing header no longer skips the timestamp.
- Null users and non-`PersonalAccessToken` tokens are still skipped.
- It keeps the query update instead of `save()`, so logout/refresh can't re-insert the token.

The added `!$token->exists` guard was not in the task list, but it is justified and documented: `Sanctum::actingAs()` mock tokens and tokens deleted in the same request. When logout deletes through a separate query, the update matches zero rows and is harmless. Both readers (`AuthService::listTokens`, `StatisticsService::overview`) work at the 5-minute precision.

TASK-3: PASS
notes: `TokenLastUsedTest.php` covers all six cases (a)–(f):
- It uses frozen time (`travelTo(startOfSecond)`) and `forgetGuards()` between requests.
- The single-UPDATE case counts writes with `DB::listen`.
- Logout and refresh run as a dataset.

TASK-4: PASS
notes: I ran both checks myself in the worktree.
- `composer test` exits 0 with 0 failures. The warnings all come from the missing `.env` (phpdotenv `file_get_contents`), and that is environmental.
- `composer analyse` reports `[OK] No errors`.
- `TokenLastUsedTest` and `ClientVersionHeaderTest` both pass.

## Security

None run. No security-relevant change: no new input surface, and the token lookup and auth are unchanged.

## Overall

APPROVED

No blockers.
