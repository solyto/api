# Verdict: Migration compatibility guard

id: model
reviewer: @reviewer
date: 2026-10-14

## Review

Branch `feature/model_migration-compatibility-guard` confirmed. Diff `main...HEAD` touches only the fixture, the new test, and job docs. No migration, `pgsql`/DAV schema, or route was changed.

TASK-1: PASS
notes: `tests/Fixtures/client-contract/1.0.15-migrations.txt` is byte-identical to `git ls-tree --name-only 1.0.15 database/migrations/` (diffed, 144 lines). All listed files still exist. The four `2026_10_10_*` files are the only migrations outside the baseline (148 total).

TASK-2: PASS
notes: `tests/Unit/MigrationCompatibilityTest.php` extracts the `up()` body with the tokenizer, drops comments, and covers every pattern in the brief, including `DB::table(...)->update/delete` across lines and the `Schema::connection(...)` / `DB::connection(...)` forms. The model-call rule follows tasks.md Interpretation 2. I ran the scanner outside Pest: the three additive `2026_10_10_*` migrations are not flagged, the backfill is flagged (`NoteCategory::`, `User::`), and the historical `restructure_library_recipes` is flagged (dropColumn, renameColumn, DB::table()->update/delete). The doc comment covers the scope rules, how to regenerate the baseline, and the allowlist format.

TASK-3: PASS
notes: The allowlist starts with exactly the brief's entry and reason. The stale-entry check fails when an allowlisted file does not exist, and the test also rejects empty reasons.

TASK-4: PASS
notes: I re-ran everything myself. `php artisan test tests/Unit/MigrationCompatibilityTest.php` is green. `composer test` exits 0 (780 tests). `composer analyse` exits 0. The "warnings" on every test are the environment's phpdotenv `.env` read warning, not failures. The red-on-removal check matches the scanner output I reproduced.

## Security

none (not run; the change adds a test and a fixture only)

## Overall

APPROVED

No blockers.
