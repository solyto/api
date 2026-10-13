# Implementation: Standards: first (tenant isolation, dev-request attribution, tracked export zip)

id: here
developer: Claude (developer agent)
date: 2026-10-13

## Summary

Removed the tracked user-data export zip and started ignoring `tmp/`.
Scoped every foreign-id `exists:` rule in the brief's file list (34 rules
across Todos, Notes, Libraries and TimeTracking) to the caller with
`Rule::exists(<table>, 'id')->where('user_id', …)`, as STANDARDS.md 3.2
requires. Added an API test per module that sends another user's id and
expects 422, and checks that nothing was stored or attached.
TASK-7 (dev-request attribution) was **not** implemented because OQ-8 is
still open (see below).

**The tests were not run.** The worktree has no `vendor/` directory, and
installing dependencies needs owner approval. Every changed PHP file passes
`php -l`. Run `composer test` (or at least `php artisan test app/Api/Todos
app/Api/Notes app/Api/Libraries app/Api/TimeTracking`) before merging.

## Changes

TASK-1: Deleted `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip` from
the tree (I did not open it) and added `/tmp/` to `.gitignore`. History was
not rewritten. `ExportUserData` defaults to `/var/www/tmp`, which is why the
directory exists.

TASK-2 (Todos): Scoped `category_id` and `tags.*` in
`StoreTodoRequest`/`UpdateTodoRequest`, `categories.*` in
`StoreTodoWorkspaceRequest`, and the inline `categories.*` rules in
`TodoWorkspaceController::attachCategories`/`detachCategories` (these use
`$request->user()->id`). Tests in `TodoApiTest.php`: a foreign
category/tag on todo store and update, and a foreign category on workspace
store, attach and detach.

TASK-3 (Notes): Scoped `category_id` and `tags.*` in
`StoreNoteRequest`/`UpdateNoteRequest`, and `parent_id` in
`StoreNoteCategoryRequest`. Tests in `NoteApiTest.php`.

TASK-4 (Libraries media): Scoped `genres.*` against each type's own genre
table (`library_books_genres`, `library_movies_genres`,
`library_games_genres`, `library_music_genres`; each one checked against its
original rule) and `tags.*` in the Store and Update requests for Books,
Movies and Games, plus `genres.*` for Music. Tests: books (genres + tags)
and music in `LibraryApiTest.php`, games and movies in
`LibraryRestApiTest.php`.

TASK-5 (Libraries links/quotes): Scoped `tags.*` in the Store and Update
requests for links and quotes, and `category_id` in
`UpdateLibraryLinkRequest`. Tests in `LibraryRestApiTest.php`.

TASK-6 (TimeTracking): Scoped `category_ids.*` in
`StoreTimeTrackingProjectRequest`/`UpdateTimeTrackingProjectRequest`. Test
in `TimeTrackingApiTest.php`.

TASK-7 (DevRequests): **Skipped.** The brief says "pending OQ-8", and
`docs/STANDARDS.md` still lists OQ-8 as unanswered (is a client-chosen
`created_by_user_id` intended for admins filing on behalf of users?). This
needs the owner's answer before it can be done.

## Known issues / follow-ups

- **Tests not executed** (no `vendor/`). They need to be run.
- **OQ-8 / TASK-7**: still open. `StoreDevRequestRequest.php:19` still
  accepts any `created_by_user_id`.
- **Git history**: the export zip is still in history (since `072fea9`), and
  the repo is public. The owner has to decide whether to rewrite history.
- **The grep check is not at 0**: there are 7 hits left. Six are the ones
  the analyst listed and are already compliant or intentional:
  TimeTracking entry `project_id` ×3 (re-scoped in the controller),
  statistics `category_id` (query is `forUser`), and `friend_id` (OQ-7) and
  `receiver_id` (which must target other users). The seventh is the
  dev-request `created_by_user_id` (OQ-8).
- `StoreLibraryLinkRequest` has no `category_id` rule, so a link can't be
  given a category on create. That might be a gap; I left it alone.
- I did not change the "Currently 40 hits, 0 scoped" text in
  `docs/STANDARDS.md` 3.2 or the checkboxes in `docs/STANDARDS_BACKLOG.md`.
