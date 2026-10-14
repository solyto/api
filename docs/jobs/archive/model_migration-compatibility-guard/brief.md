# Brief: Migration compatibility guard

type: feature
id: model
branch: feature/model_migration-compatibility-guard
date: 2026-10-14
author: Leander Muskalla

## What
A test that fails when a migration added after the oldest supported frontend build (1.0.15) changes existing data or schema in a way an old client can notice, unless that migration is allowlisted with a reason (`docs/STANDARDS.md` 13.3, 13.4).

- Baseline: `tests/Fixtures/client-contract/1.0.15-migrations.txt`, the migration filenames at tag `1.0.15`. Generate it once with `git ls-tree --name-only 1.0.15 database/migrations/` (144 files). The test does not call git.
- Test: `tests/Unit/MigrationCompatibilityTest.php`. Read every file in `database/migrations/` that is not in the baseline, and flag:
  - `dropColumn`
  - `renameColumn`
  - `->change()`
  - `Schema::drop` / `dropIfExists`
  - `Schema::rename`
  - `DB::statement`
  - `DB::table(...)->update(` / `->delete(`
  - model calls in `up()` that write rows (backfills, such as `NoteCategory::provisionJournal`)
- Allowlist: a constant in the test, `filename => reason`. The reason names the backlog entry or decision that makes the migration safe. A listed migration that no longer exists fails the test, so the list stays clean.
- Start state: `2026_10_10_000001_backfill_journal_categories.php` is flagged. Allowlist it with the reason "hidden from old clients by `hidden-note-categories-opt-in`".

## Why
The two kinds of change that hurt old apps most can't be seen in the response shape: destructive migrations (recipe `ingredients`, 1.0.13 → 1.0.15) and backfills (`.journal`). Contract tests don't see them. This guard makes them a deliberate decision, deterministically and without an agent in the deploy.

## Out of scope
- Changing any migration.
- Migrations up to and including 1.0.15.
- The `pgsql` DAV migrations: the guard reads them like any other file, and no DAV schema change is made.

## Notes
The guard is a heuristic: it catches the listed patterns, not every possible data change. Rule 13.3 still applies at review time. When support for 1.0.15 ends, regenerate the baseline at the new oldest supported tag and remove allowlist entries that are now inside it.

Check: `php artisan test tests/Unit/MigrationCompatibilityTest.php`, then `composer test` and `composer analyse` exit 0.
