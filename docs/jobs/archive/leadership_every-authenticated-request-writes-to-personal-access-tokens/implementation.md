# Implementation: Every authenticated request writes to `personal_access_tokens`

id: leadership
developer: developer
date: 2026-11-10

## Summary

Turned off Sanctum's own `last_used_at` tracking, which wrote on every request. `RecordClientVersion` now sets `last_used_at` at most once per 5 minutes per token. It combines that with the `client_version` change into one `UPDATE`. The middleware keeps its name, so `bootstrap/app.php` is unchanged. `composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `config/sanctum.php` now has `'last_used_at' => false`, so Sanctum's guard skips `updateLastUsedAt`.

TASK-2: `app/Shared/Middleware/RecordClientVersion.php`. After `$next`, the middleware builds one change array:
- `last_used_at` when it is null or older than `LAST_USED_THRESHOLD_MINUTES` (5).
- `client_version` when a valid header differs from the stored value.

If the array is not empty it runs one `whereKey()->update()`. The early return on a missing header is gone, so old builds and widgets still update `last_used_at`. Tokens that aren't saved in the database (`!$token->exists`) are skipped. Without that check, `Sanctum::actingAs()` crashed: it sets a Mockery mock token whose attributes return `false`, which broke `ApiTrafficAlertTest`. The check also skips tokens that logout/refresh delete on the same instance.

TASK-3: New file `app/Api/Users/Tests/TokenLastUsedTest.php` covering:
- the first request sets `last_used_at`
- no write within 5 minutes
- a write after more than 5 minutes
- a stale timestamp updates without the header
- a version change plus a stale timestamp give exactly one `UPDATE`, counted with `DB::listen`
- logout/refresh don't recreate the token

`ClientVersionHeaderTest` was left as it is.

TASK-4: Full `composer test` passes (0 failed) and `composer analyse` reports no errors. No test reads the token's `last_used_at` through `AuthService::listTokens` or `StatisticsService`.

## Known issues / follow-ups

- There was no `.env` and no `storage/framework/*` in the worktree. I created the (gitignored) storage dirs and ran `composer install` from the lock file to get tests running. The test warnings come only from the missing `.env` (phpdotenv `file_get_contents`).
- `.env.testing` shows as modified in `git status`. It is a character-device mount, not a file, so I left it alone and didn't commit it.
- Renaming the middleware (e.g. `TouchAccessToken`) is still an owner call (brief §4.3).
