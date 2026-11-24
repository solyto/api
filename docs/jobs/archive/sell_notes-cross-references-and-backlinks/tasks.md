# Tasks: notes-cross-references-and-backlinks

id: sell
analyst: silvio
date: 2026-11-24

## Task breakdown

TASK-1: Migration `create_note_links_table`: `source_note_id`, `target_note_id`
(uuid FKs to `notes`, both cascade on delete), unique pair, index on
`target_note_id`.
files: database/migrations/2026_11_24_000001_create_note_links_table.php
depends: none
risk: low — purely additive table

TASK-2: `Note` self-relations `linkedNotes()` and `backlinks()` over `note_links`.
files: app/Api/Notes/Models/Note.php
depends: TASK-1
risk: low

TASK-3: `NoteService::syncLinks()` (scan `/notes/{uuid}` in content, keep the
user's own other notes, sync), called from `create()` and from `update()` when
`content` is written; `NoteService::backlinks()` with the visibility filter.
files: app/Api/Notes/Services/NoteService.php
depends: TASK-2
risk: medium — note write path

TASK-4: Both `NoteImportService` paths run the scan after saving.
files: app/Api/Notes/Services/NoteImportService.php
depends: TASK-3
risk: low

TASK-5: `GET /notes/{note}/backlinks`: controller action with `@OA\Get`, route,
`NoteBacklinkResource` (`id`, `title`) with `@OA\Schema`.
files: app/Api/Notes/Controllers/NoteController.php, routes/api.php,
app/Api/Notes/Resources/NoteBacklinkResource.php
depends: TASK-3
risk: low

TASK-6: Backfill data migration running the scan over every existing note,
plus its `MigrationCompatibilityTest` allowlist entry.
files: database/migrations/2026_11_24_000002_backfill_note_links.php,
tests/Unit/MigrationCompatibilityTest.php
depends: TASK-3
risk: low–medium — one pass over all notes on deploy; writes only the new table

TASK-7: Tests: links on create/update, removal, update without content,
other user's targets, self-links, HTML/Markdown/absolute URLs, target and
source deletion, hidden sources, 403/404, backfill, both import paths.
files: app/Api/Notes/Tests/NoteLinkTest.php
depends: TASK-1..6
risk: low
