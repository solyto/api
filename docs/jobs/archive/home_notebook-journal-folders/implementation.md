# Implementation: notebook: journal folders

id: home
developer: developer
date: 2026-10-10

## Summary

Every user now owns exactly one protected, hidden, root-level journal folder
(`title = '.journal'`, `is_system = true`, `is_hidden = true`), provisioned by
the `UserObserver` on creation and backfilled for existing users by a migration.
Two additive boolean columns back the flags, `is_system` folders are immutable
through the API, and `is_hidden` is toggled through a dedicated endpoint. The
`GET /v1/notes/newest` feed excludes notes that live in hidden folders while
`GET /v1/notes` and `GET /v1/notes/categories` stay complete.

## Changes

TASK-1: `database/migrations/2026_10_10_000000_add_system_and_hidden_to_note_categories.php`
— adds `is_system` and `is_hidden` booleans (default `false`) after `sort_order`;
`down()` drops them.

TASK-2: `app/Api/Notes/Models/NoteCategory.php` — added the `JOURNAL_TITLE`
constant, both flags to `$fillable` and `$casts` (boolean), model attribute
defaults (`false`) so freshly created models expose real booleans before a
refresh, and a `provisionJournal(User)` helper (idempotent `firstOrCreate`) whose
journal definition lives once. The helper is a static model method rather than a
service method so the backfill migration can reuse it without depending on an
application service; the observer also calls it.

TASK-3: `database/migrations/2026_10_10_000001_backfill_journal_categories.php`
— iterates existing users via `chunkById(500)` and calls
`NoteCategory::provisionJournal` per user, skipping anyone who already owns a
root-level `.journal` folder. `down()` is a no-op (journal folders are user data).

TASK-4: `app/Api/Users/Observers/UserObserver.php` — `created()` also calls a new
`createJournalCategory()` private method, which provisions the journal folder via
the model helper.

TASK-5: `app/Api/Notes/Controllers/NoteCategoryController.php` — `update()` and
`destroy()` abort with 403 for `is_system` folders, after the existing ownership
check. `is_system` remains non-settable because no form request validates it.

TASK-6: `routes/api.php` — `PATCH /v1/notes/categories/{category}/hidden`
registered before the `apiResource`;
`app/Api/Notes/Requests/UpdateNoteCategoryHiddenRequest.php` validates
`hidden => required|boolean`; `NoteCategoryController::updateHidden()` runs the
ownership check (system folders allowed) and delegates to
`NoteCategoryService::updateHidden()`, which persists `is_hidden` and invalidates
both the `note_categories` cache and `NoteService`'s `notes_newest` cache (via a
new `NoteService::forgetNewest(string $userId)` method). `NoteCategoryService`
now injects `NoteService` for that cache invalidation.

TASK-7: `app/Api/Notes/Services/NoteService.php` — `newest()` adds
`whereDoesntHave('category', fn ($q) => $q->where('is_hidden', true))`, which
excludes hidden-folder notes while keeping uncategorized notes. `list()` is
untouched.

TASK-8: `app/Api/Notes/Resources/NoteCategoryResource.php` — exposes
`is_system`/`is_hidden` and documents both in the `NoteCategory` OpenAPI schema.

TASK-9: `app/Api/Notes/Factories/NoteCategoryFactory.php` — `is_system`/
`is_hidden` default to `false` and new `system()` / `hidden()` states were added.

TASK-10: `app/Api/Notes/Tests/NoteApiTest.php` — fixed the two category-count
assertions (list roots now 2, remaining rows after delete now 1) and added
coverage for journal provisioning (flags + root-level + idempotency), 403 on
renaming/deleting a system folder, `is_system` never settable through create or
update, the hidden toggle (ordinary + system folder, validation, cross-user 403),
and `newest` excluding hidden-folder notes while keeping uncategorized ones.
`app/Api/Notes/Tests/NoteTest.php` — fixed the global `roots()` count (now 4) and
added factory default/`system()`/`hidden()` tests. `StatisticsTest` needs no
change (it asserts no exact `note_folders` count).

## Known issues / follow-ups

- The backfill migration does not invalidate the per-user `note_categories`
  cache (Redis `user_data`, 24h TTL). For existing users whose category list was
  cached before deploy, the journal folder may not appear in
  `GET /v1/notes/categories` until that entry expires or the cache is cleared.
  This matches the task breakdown (cache invalidation is only specified for the
  hidden toggle) but is worth a one-off cache clear on deploy if that matters.
- `.journal` is included in exports as `journal/` (leading dot trimmed by
  `NoteExportService::sanitizeFilename`) — accepted in the brief.
- Environment only: the suite emits a pre-existing `file_get_contents(/workspace/.env)`
  warning (no `.env` in this worktree) and needed `storage/`/`vendor/` scaffolding;
  neither is related to the change. Full suite result: 0 failures.
- Working tree still shows the pre-existing `.env.testing` and `AGENTS.md`
  modifications and the harness `tokens.json` — these are environment/mount
  artifacts, not part of this change (`.env.testing` is a device and must never
  be committed).
