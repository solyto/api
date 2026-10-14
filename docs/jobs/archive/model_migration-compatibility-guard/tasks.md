# Tasks: Migration compatibility guard

id: model
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Context checked

- The branch is `feature/model_migration-compatibility-guard`, which matches the brief. The worktree HEAD points to that ref.
- Tag `1.0.15` exists in the main repo (`.git/refs/tags/1.0.15`).
- `database/migrations/` is flat (no subfolders). The DAV (`pgsql`) migrations sit in the same folder and are scanned like any other file, as the brief says.
- `phpstan.neon` only analyses `app/`, so the new test under `tests/Unit/` is not analysed by PHPStan. `composer analyse` still has to exit 0.
- Pattern to follow: `tests/Unit/ResourceSchemaDriftTest.php`. It is a static source scan with a top-level `const` allowlist, a doc comment that explains the entry format, and one `expect()` with a message listing what is new and what is fixed.
- The newest migrations are the four `2026_10_10_*` files. Three of them are additive (`add_system_and_hidden_to_note_categories`, `add_color_to_time_tracking_projects`, `add_journal_display_settings_to_user_settings`), **but their `down()` methods call `dropColumn`**.

## Interpretation (read before implementing)

1. **Only the body of `up()` is scanned, for every pattern.** The brief says only `2026_10_10_000001_backfill_journal_categories.php` is flagged at the start. If the whole file were scanned, the `dropColumn` calls in the `down()` methods of the three additive migrations would also be flagged. So scanning only `up()` is the only reading that matches the start state. Say this in the test's doc comment.
2. **How to detect "model calls in `up()` that write rows".** The brief names this pattern but gives no exact rule. Conservative suggestion: flag any class imported with `use App\...` that is referenced inside `up()`. In the backfill, that catches both `User::query()` and `NoteCategory::provisionJournal`. This over-flags rather than under-flags, which fits "make it a deliberate decision". If the developer picks a narrower rule (for example, a list of write method names), it must still flag `NoteCategory::provisionJournal`. Write the rule down in the doc comment.
3. **`DB::table(...)->update(` / `->delete(` can span several lines.** Match it across whitespace and newlines.
4. **Baseline file format.** Keep the raw output of `git ls-tree --name-only 1.0.15 database/migrations/` (paths include `database/migrations/`), so anyone can regenerate the file with the same command. The test compares by `basename()`.
5. **Stop condition.** After the baseline is generated, the set of non-baseline migrations should be exactly the four `2026_10_10_*` files, with the backfill as the only one flagged. If the baseline does not have 144 lines, or if any other migration is flagged, stop and report it. Do not allowlist extra migrations without a human decision, because the reason text has to name a real backlog entry or decision.

## Task breakdown

TASK-1: Generate the 1.0.15 migration baseline fixture with `git ls-tree --name-only 1.0.15 database/migrations/` and commit its output unchanged (expected: 144 lines).
  files: `tests/Fixtures/client-contract/1.0.15-migrations.txt` (new; the `client-contract/` folder is new too)
  depends: none
  risk: low — data-only file, but check the line count is 144 and that every listed file still exists in `database/migrations/` (migrations are not deleted, so a missing one means the baseline is wrong).

TASK-2: Add `tests/Unit/MigrationCompatibilityTest.php`. It reads the baseline, and for every migration not in the baseline it scans the `up()` body for the patterns in the brief. It fails on any flagged migration that is not in the `filename => reason` allowlist constant.
  files: `tests/Unit/MigrationCompatibilityTest.php` (new)
  depends: TASK-1
  risk: medium — the `up()` extraction and the "model write" heuristic are custom parsing. Too broad flags additive migrations; too narrow misses the backfill (see Interpretation 1–3).

TASK-3: In the same test, start the allowlist with `2026_10_10_000001_backfill_journal_categories.php` => "hidden from old clients by `hidden-note-categories-opt-in`". Add a check that fails when an allowlisted filename does not exist in `database/migrations/`.
  files: `tests/Unit/MigrationCompatibilityTest.php`
  depends: TASK-2
  risk: low — straightforward assertion. The backlog entry `docs/backlog/hidden-note-categories-opt-in.md` exists, so the reason refers to a real entry.

TASK-4: Verify: `php artisan test tests/Unit/MigrationCompatibilityTest.php`, then `composer test` and `composer analyse` all exit 0. As a manual sanity check (not committed), temporarily remove the allowlist entry and confirm the test goes red, naming the backfill file.
  files: none
  depends: TASK-3
  risk: low — the change adds files only. No migration, `pgsql`/DAV schema, or route is touched.

## Out of scope / not planned

- No migration is changed or run.
- No tooling changes (PHPStan paths, Makefile).
- No changes to `docs/STANDARDS.md`. Rule 13.7 step 3 already mentions regenerating the baseline.
- Removing or closing `docs/backlog/migration-compatibility-guard.md` once this is done is not part of the brief. Leave it to the owner or pipeline.
