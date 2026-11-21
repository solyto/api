# Tasks: Time-tracking entries have no index for the way they are queried

id: decade
analyst: analyst
date: 2026-11-19

## Scope

Only the brief's suggestion 1 (the recommended one): one additive migration
that adds two composite indexes to `time_tracking_entries`. The single-column
FK indexes on `user_id` and `project_id` stay as they are, which is what the
brief recommends.

Out of scope:
- Suggestion 2 (bounding `GET /time-tracking/entries` with a window or
  pagination). It changes the API contract and needs the frontend to agree
  first (STANDARDS 13.x).
- A covering index for `withSum('entries', 'duration_minutes')`. The brief
  says not to add it.
- Any change to services, queries or the `pgsql`/DAV schema.

## Task breakdown

TASK-1: Add one new migration that creates composite indexes `(user_id, started_at)` and `(project_id, started_at)` on `time_tracking_entries`, and drops exactly those two in `down()`.
  files: `database/migrations/2026_11_19_000001_add_started_at_indexes_to_time_tracking_entries.php` (new)
  depends: none
  risk: low. It only adds indexes on the default (MariaDB) connection and changes no behaviour or contract.
  notes:
  - Follow the shape of `database/migrations/2025_09_17_150031_add_is_completed_index_to_todos.php`: anonymous class, `Schema::table`, `$table->index([...])` in `up()`, `$table->dropIndex([...])` with the same column arrays in `down()`.
  - The generated names (`time_tracking_entries_user_id_started_at_index`, 45 chars; `time_tracking_entries_project_id_started_at_index`, 48 chars) are under MariaDB's 64-character limit, so they don't need explicit names.
  - Don't touch the original `2026_02_18_000001_create_time_tracking_tables.php` (STANDARDS 12.6: migrations are never edited after deploy).
  - Hard rule: show the migration before running `php artisan migrate` against any real database. The test suite runs it against in-memory SQLite, which is fine.
  - In `down()`, dropping the composite indexes is safe. Both FK columns keep their original single-column indexes, so MariaDB won't refuse the drop because of a foreign key.

TASK-2: Run `composer test` and `composer analyse` in the worktree and confirm both exit 0.
  files: none
  depends: TASK-1
  risk: low. Indexes don't change query results, and the existing time-tracking tests already cover the affected queries.
