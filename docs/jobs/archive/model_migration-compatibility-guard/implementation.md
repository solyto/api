# Implementation: Migration compatibility guard

id: model
developer: @developer
date: 2026-10-14

## Summary

Added a static Pest guard that fails when a migration added after the 1.0.15 baseline changes existing data or schema in its `up()` method (STANDARDS 13.3, 13.4), unless it is allowlisted with a reason. The only flagged migration is the journal backfill, and it is allowlisted with a reference to the `hidden-note-categories-opt-in` backlog entry.

## Changes

TASK-1: `tests/Fixtures/client-contract/1.0.15-migrations.txt`: raw output of `git ls-tree --name-only 1.0.15 database/migrations/`, unchanged (144 lines). Every listed file still exists. The migrations outside the baseline are exactly the four `2026_10_10_*` files.

TASK-2: `tests/Unit/MigrationCompatibilityTest.php`: compares migrations by basename against the baseline. For each newer migration it extracts the `up()` body with the tokenizer, drops comments, and scans for these patterns:
- `dropColumn(s)`, `renameColumn`, `->change()`
- `Schema::drop` / `dropIfExists` / `rename`, also when called through `Schema::connection(...)`
- `DB::statement`, also through `DB::connection(...)`
- `DB::table(...)` … `->update(` / `->delete(` within one statement, across lines
- static calls (`Class::`) on any class imported via `use App\...` or written as `\App\...`

Only `up()` is scanned, so the additive migrations whose `down()` calls `dropColumn` are not flagged. The model-call rule deliberately over-flags. The doc comment covers the rules, how to regenerate the baseline, and the allowlist format.

TASK-3: Same file. The `MIGRATION_COMPATIBILITY_ALLOWLIST` constant starts with `2026_10_10_000001_backfill_journal_categories.php` => "hidden from old clients by `hidden-note-categories-opt-in`". A separate test fails on allowlist entries whose file does not exist or whose reason is empty. A third test checks that the baseline has 144 entries and that each one still exists.

TASK-4: Verification:
- `php artisan test tests/Unit/MigrationCompatibilityTest.php`: green.
- Allowlist entry temporarily removed: the test goes red and names `2026_10_10_000001_backfill_journal_categories.php (model call NoteCategory::, model call User::)`. The change was reverted.
- Sanity run of the scanner over all 148 migrations: it flags the historical cases you would expect, including `2026_07_24_120000_restructure_library_recipes.php` (dropColumn, renameColumn, DB::table()->update/delete).
- `composer analyse`: exit 0.
- `composer test`: exit 0 on four consecutive runs (780 tests).

## Known issues / follow-ups

- The very first `composer test` run (right after `composer install`) reported 2 failures. The output was truncated, so I could not identify them. Four reruns were all green, and the failures did not come from the new test file. They may be flaky tests elsewhere.
- Environment: `vendor/` was missing, so I ran `composer install` from the lock file without adding packages. `storage/framework/{views,cache,sessions,testing}` were missing and I created them; they are gitignored. `.env` does not exist and `.env.testing` is mounted as a character device. Because of that, every test, old and new, shows a phpdotenv "file_get_contents(.env)" warning. Warnings do not fail the suite. `.env.testing` shows as modified in `git status` but cannot be staged, and I left it alone.
- `docs/backlog/migration-compatibility-guard.md` is still open (out of scope per tasks.md).
