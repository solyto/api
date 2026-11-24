## Summary

API part of `notes-cross-references-and-backlinks`. A `note_links` table is kept up to date by the API on every note content write (create, update, import), `GET /api/v1/notes/{note}/backlinks` lists the notes that link to a note, and a data migration backfills the links that existing notes already contain. Everything is additive: `NoteResource` and the existing note endpoints are unchanged.

## Changes

TASK-1: `database/migrations/2026_11_24_000001_create_note_links_table.php`. The `note_links` table has `source_note_id` and `target_note_id` (UUID FKs to `notes`, both cascade on delete), a composite primary key and an index on `target_note_id`. Deleting a note removes only its rows; the link text in other notes stays (decision 6).

TASK-2: `app/Api/Notes/Models/Note.php`. Added the `linkedNotes()` and `backlinks()` `BelongsToMany` self-relations over `note_links`. No pivot model.

TASK-3: `app/Api/Notes/Services/NoteService.php`. `syncLinks(Note)` matches `/notes/{uuid}` in the raw content (case-insensitive, so HTML `href`s, Markdown links and absolute URLs all count). It keeps only the same user's other existing notes (`forUser`, rule 3.2) and syncs the rows. `create()` always calls it, and `update()` calls it when `content` is written. `backlinks(Note)` returns the source notes' `id` and `title`, filtered with the existing `whereVisible()` (hidden/system categories and their descendants left out, decision 7) and sorted by title. Backlinks are not cached.

TASK-4: `app/Api/Notes/Services/NoteImportService.php`. Both import paths (a single `.md` file and notes in a zip) save notes directly, so they now call `syncLinks()` after saving.

TASK-5: `GET /notes/{note}/backlinks`. Added `NoteController::backlinks` (owner check → 403, unknown id → 404, `@OA\Get` `noteBacklinks`), its route in `routes/api.php` before the `apiResource` line, and `app/Api/Notes/Resources/NoteBacklinkResource.php` (`id`, `title`, `@OA\Schema` `NoteBacklink`). The contract is the room decision of 2026-11-24.

TASK-6: `database/migrations/2026_11_24_000002_backfill_note_links.php` runs `syncLinks()` over every note in chunks of 500 (decision 9, owner: data migration). It has an allowlist entry in `tests/Unit/MigrationCompatibilityTest.php` because it calls app code; it writes only the new table.

TASK-7: `app/Api/Notes/Tests/NoteLinkApiTest.php` (14 tests). They cover:
- links stored on create and update, replaced and removed when the content changes, and kept on an update without `content`
- HTML, Markdown and absolute or upper-case links, with duplicates counted once
- self-links, unknown ids and other users' notes ignored
- deleting the target drops the row and keeps the source text; deleting the source drops the backlink
- sorting, hidden categories and their subfolders, 403/404/401
- both import paths, and the backfill

`composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- The worktree has no `.env` (`.env.testing` is mounted as a device), so every test prints a dotenv "file_get_contents(/workspace/.env)" warning. This is environmental, not from this change; the suite passes.
- Links between notes inside one zip import point at the old IDs and stay unlinked (decision 8, accepted).
- `syncLinks()` is public because the import and the backfill migration call it.
