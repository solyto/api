# Implementation: Time-tracking entries have no index for the way they are queried

id: decade
developer: developer
date: 2026-11-19

## Summary

Added one additive migration with two composite indexes on `time_tracking_entries` (brief suggestion 1). No query, service or API changes.

## Changes

TASK-1: New `database/migrations/2026_11_19_000001_add_started_at_indexes_to_time_tracking_entries.php` adds `(user_id, started_at)` and `(project_id, started_at)`; `down()` drops exactly those two. Shape follows `2025_09_17_150031_add_is_completed_index_to_todos.php`. The single-column FK indexes stay. Not run against any real database — needs to be shown before `php artisan migrate` (hard rule).
TASK-2: `composer test` exited 0 (all tests pass; the 1791 warnings are `file_get_contents(/workspace/.env)` failing because the worktree has no `.env`, unrelated to this change). `composer analyse` exited 0. `vendor/` had to be installed from the lock file and the gitignored `storage/framework/*` directories created first.

## Known issues / follow-ups

- Suggestion 2 (bounding `GET /time-tracking/entries`) stays out of scope; it needs frontend agreement.
