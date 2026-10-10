# Tasks: notebook: journal folders

id: home
analyst: analyst
date: 2026-10-10

<!-- Produced by @analyst from brief.md. -->

## Scope check

Branch: `/workspace/.git` is a worktree file pointing at
`.../.git/worktrees/home_notebook-journal-folders`, matching the brief's
`branch: feature/home_notebook-journal-folders`. Proceeding.

The brief is unambiguous, so no open questions block this plan. Two
clarifications are recorded as assumptions under "Notes" at the bottom.

## Task breakdown

<!-- TASK-1: description
     files: list of files likely affected
     depends: none
     risk: low / medium / high — reason

TASK-2: ...
-->

TASK-1: Add `is_system` and `is_hidden` boolean columns (default `false`,
`after('sort_order')`) to `note_categories` via a new migration.
files: `database/migrations/<new>_add_system_and_hidden_to_note_categories.php`
depends: none
risk: medium — additive schema change, but repo hard rule requires the
migration be shown before it is ever run.

TASK-2: Extend the `NoteCategory` model — add `is_system`/`is_hidden` to
`$fillable`, cast both to `boolean`, and add a single `JOURNAL_TITLE = '.journal'`
constant shared by the observer and the backfill migration.
files: `app/Api/Notes/Models/NoteCategory.php`
depends: none (code only; casts matter once TASK-1 is applied)
risk: low — small, local model change with a boolean cast.

TASK-3: Add a backfill migration that inserts one root-level journal folder
(`title = '.journal'`, `is_system = true`, `is_hidden = true`, `parent_id = null`)
per existing user, skipping any user that already owns a `.journal` folder.
Must be idempotent and iterate users in chunks.
files: `database/migrations/<new>_backfill_journal_categories.php`
depends: TASK-1, TASK-2
risk: high — writes rows for every existing user against production data;
ordering, idempotency, and the "don't run without showing it" rule all apply.

TASK-4: Provision the journal folder for new users in `UserObserver::created`
(skip if the user already has one). Prefer a small helper (e.g. a method on
`NoteCategoryService`) used by both task 3 and the observer so the `.journal`
definition lives once.
files: `app/Api/Users/Observers/UserObserver.php`,
`app/Api/Notes/Services/NoteCategoryService.php` (optional helper)
depends: TASK-1, TASK-2
risk: medium — runs on every user creation including tests, so it shifts
per-user category counts broadly.

TASK-5: Reject rename and delete of `is_system` folders with HTTP 403 in
`NoteCategoryController::update` and `::destroy` (after the existing ownership
check). `is_system` stays non-settable through requests because the
Store/Update form requests never validate it.
files: `app/Api/Notes/Controllers/NoteCategoryController.php`
depends: TASK-2
risk: low — a guard clause in two existing methods.

TASK-6: Add `PATCH /v1/notes/categories/{category}/hidden` accepting
`{ "hidden": bool }` (required|boolean), allowed on any folder including system
ones, with the usual 403 ownership check. Add the route before the
`apiResource`, a dedicated FormRequest, a service method that persists
`is_hidden` and invalidates both the `note_categories` and `notes_newest`
caches (the latter is owned by `NoteService`).
files: `routes/api.php`,
`app/Api/Notes/Controllers/NoteCategoryController.php`,
`app/Api/Notes/Requests/UpdateNoteCategoryHiddenRequest.php` (new),
`app/Api/Notes/Services/NoteCategoryService.php`
depends: TASK-2
risk: medium — new endpoint plus cross-cutting cache invalidation of a second
service's key.

TASK-7: Exclude notes whose category is `is_hidden` from
`NoteService::newest` (e.g. `whereDoesntHave('category', fn ($q) => $q->where('is_hidden', true))`),
while still including uncategorized notes. Leave `NoteService::list` complete.
files: `app/Api/Notes/Services/NoteService.php`
depends: TASK-2, TASK-6
risk: medium — query semantics must keep null-category notes; interacts with
the cached `notes_newest` key invalidated in task 6.

TASK-8: Expose `is_system` and `is_hidden` from `NoteCategoryResource` and add
both properties to its `@OA\Schema(schema="NoteCategory")` annotation (this is
where the OpenAPI `NoteCategory` schema actually lives; `OpenApiSpec.php` only
holds the envelope schemas).
files: `app/Api/Notes/Resources/NoteCategoryResource.php`
depends: TASK-2
risk: low — additive resource/annotation fields.

TASK-9: Add factory support for the new flags: `is_system = false` and
`is_hidden = false` defaults plus `system()` / `hidden()` states, so tests can
build both the journal and ordinary folders.
files: `app/Api/Notes/Factories/NoteCategoryFactory.php`
depends: TASK-2
risk: low — factory defaults/states only.

TASK-10: Update the fallout and add coverage: fix `NoteApiTest` category count
assertions (lines ~85 and ~96 — now +1 for the provisioned journal) and
`NoteTest`'s global `NoteCategory::roots()` count (~line 176); check
`StatisticsTest` for any exact `note_folders` expectation; add tests for journal
provisioning (observer + existing behavior), `is_system` 403 on update/delete,
the hidden toggle endpoint (including on a system folder), and `newest`
excluding hidden-folder notes while keeping uncategorized ones.
files: `app/Api/Notes/Tests/NoteApiTest.php`, `app/Api/Notes/Tests/NoteTest.php`,
`app/Api/Statistics/Tests/StatisticsTest.php` (if assertions shift)
depends: TASK-1 … TASK-9
risk: medium — several existing assertions change meaning once every user gets
a folder; must distinguish intentional shifts from real regressions.

## Notes / assumptions

- Public route shape is `PATCH /v1/notes/categories/{category}/hidden` per the
  brief; the controller action name is left to the developer.
- `is_hidden` is modelled as a general folder property but is only reachable
  through the dedicated endpoint (no form request accepts it on store/update),
  matching "the frontend won't expose it for ordinary folders yet".
- Export (`NoteExportService`) already walks all categories and notes and its
  `sanitizeFilename` trims the leading dot, so `.journal` → `journal/`; no change
  is planned there (accepted in the brief).
- Recursive/descendant hiding and any pre-existing `.journal` adoption are
  explicitly out of scope.
