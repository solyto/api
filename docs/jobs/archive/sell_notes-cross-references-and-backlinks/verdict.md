# Verdict: notes-cross-references-and-backlinks

id: sell
reviewer: carmela (reviewer)
date: 2026-11-24

## Review

Branch `feature/sell_notes-cross-references-and-backlinks`, diff `main...HEAD`.
Every changed file appears in `implementation.md`. Nothing outside `tasks.md`
changed. `docs/policies/` and `docs/AGENTS.md` are untouched.

`composer test`: exit 0, full suite (the dotenv warnings come from the missing
`.env` in the worktree). The 14 new tests are green. `composer analyse`: exit 0,
no errors.

TASK-1: PASS
notes: `note_links` has UUID FKs to `notes` that cascade on both sides, a
composite primary key on the pair and an index on `target_note_id`. The table is
additive and the `id` type matches `notes.id`.

TASK-2: PASS
notes: The `linkedNotes()` and `backlinks()` self-relations each carry the
`@return BelongsToMany<Note, $this>` generic (rule 10.2).

TASK-3: PASS
notes: `syncLinks()` resolves targets through `Note::forUser()`, excludes the
note itself with `whereKeyNot`, and uses no raw SQL (rules 3.2, 5.2, 5.5).
`create()` always calls it. `update()` calls it only when `content` is in the
payload. `backlinks()` reuses `whereVisible()`, so hidden and system categories
and their descendants are left out (decision 7), and it sorts by title. Not
caching the result matches the plan.

TASK-4: PASS
notes: The markdown import and the zip import both call `syncLinks()` after
`save()`. Tests cover both paths.

TASK-5: PASS
notes: The controller follows rule 1.1 (ownership `abort_unless` → one service
call → `ApiResponse::success`). The route is placed before `apiResource`
(rule 1.4). In the `@OA\Get`, the path is relative (2.1), the response uses
`allOf SuccessResponse` (2.3), `operationId="noteBacklinks"` is unique (2.5),
and the 403/404 responses use `ErrorResponse`. `NoteBacklinkResource` has
`@OA\Schema` + `@mixin Note`, and its schema matches `toArray()` (6.2). It
matches the room contract decision.

TASK-6: PASS
notes: The data migration runs `syncLinks()` over every note with
`chunkById(500)` and writes only `note_links`. It has its
`MigrationCompatibilityTest` allowlist entry with a reason (rule 12.6,
decision Q4). The down() comment explains why the method is empty. Per the
project hard rule, it still has to be shown in the room before it runs against
a real DB. That is a deploy step, not a code finding.

TASK-7: PASS
notes: `app/Api/Notes/Tests/NoteLinkApiTest.php` covers every case listed in
the task: create/update/remove links, updates without `content`,
HTML/Markdown/absolute/upper-case links, deduplication, self/unknown/foreign
targets, target and source deletion, sorting, hidden categories and their
subfolders, 403/404/401, both import paths and the backfill. The file name
differs from `tasks.md` (`NoteLinkTest.php`), but `<Module>…ApiTest.php` is the
TESTING.md convention for HTTP tests. The file-local helpers don't collide with
any other test file.

## Security

Not run separately. From the correctness side: link targets are scoped to the
owner, the endpoint checks ownership before reading, and only `id`/`title` of
the caller's own notes are returned.

## Overall

APPROVED

Nothing has to change before merge. Show the backfill migration
(`2026_11_24_000002_backfill_note_links.php`) in the room before it runs
against a real database, as agreed.
