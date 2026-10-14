# Implementation: Remove dead service config

id: size
developer: Claude (developer agent)
date: 2026-10-14

## Summary

Removed the two unused blocks (`bremerstadtreinigung` and `radicale`) from `config/services.php`. Before removing them, a repo-wide grep (excluding `vendor/`) found `bremerstadtreinigung`, `trash_calendar_url`, `radicale` and `RADICALE_URL` only in `config/services.php` and in docs (backlog entry, job files). No `.env.example` or Docker/compose files exist in this repo.

## Changes

TASK-1: Deleted the `'bremerstadtreinigung' => ['trash_calendar_url' => …]` block and the blank line after it from `config/services.php` (commit 3ed66f7).
TASK-2: Deleted the `'radicale' => ['url' => env('RADICALE_URL')]` block and the blank line after it from `config/services.php` (commit 0222432). This does not touch the `pgsql` DAV connection or the DAV routes.
TASK-3: Checks in the worktree: `php -l config/services.php` is clean, `composer analyse` exits 0 ("No errors"), and `composer test` exits 0 (839 passed, 3052 assertions) on three consecutive runs. No files changed.

## Known issues / follow-ups

- Setting up the environment: the worktree had no `vendor/` and no `storage/framework/*`. I ran `composer install` from the existing lock file (no new packages) and created the gitignored `storage/framework/{views,cache,sessions,testing}` directories. On a completely fresh worktree, the first `composer test` run had 2 failures in `app/Api/Export/Tests/ExportTest.php` (FinanceWealthExportService). They are caused by `storage/app/public/user/` not existing yet, so they depend on test order and environment and are unrelated to this job. Every later run passed. This may be worth a separate backlog entry (e.g. have the test or service create the directory).
- `docs/backlog/remove-dead-service-config.md` was left as is. Marking it done or removing it is for the backlog workflow or the owner.
- `RADICALE_URL` may still be set in the external deployment repo or production env. It's harmless now, but can be cleaned up there separately.
