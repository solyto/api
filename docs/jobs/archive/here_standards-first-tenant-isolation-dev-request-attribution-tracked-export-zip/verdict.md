# Verdict: Standards: first (tenant isolation, dev-request attribution, tracked export zip)

id: here
reviewer: Claude (reviewer agent)
date: 2026-10-13

Branch verified: `feature/here_standards-first-tenant-isolation-dev-request-attribution-tracked-export-zip`.
Review surface: `git diff main...HEAD` (base `main` from `.manigot/manigot.json`).

## Review

TASK-1: PASS
notes: Commit `1b01385` deletes `tmp/export_0198c253-…zip` and adds `/tmp/` to
`.gitignore` (`git check-ignore` confirms `tmp/*` is now ignored). The history
was not rewritten, and the developer correctly left that to the owner. `git ls-files tmp` is empty.

TASK-2: PASS
notes: `category_id` and `tags.*` in `StoreTodoRequest`/`UpdateTodoRequest`,
`categories.*` in `StoreTodoWorkspaceRequest`, and the inline rules in
`TodoWorkspaceController::attachCategories`/`detachCategories` (these use
`$request->user()->id`, after the existing ownership `abort_unless`) are all
`Rule::exists(...)->where('user_id', …)`. The `nullable`/`sometimes` modifiers are kept. The tests
cover store/update (category and tag) and workspace store/attach/detach, and assert
that nothing was stored. `TodoFactory` doesn't set a default `category_id`, so the
`toBeNull()` assertion holds.

TASK-3: PASS
notes: The `StoreNoteRequest`/`UpdateNoteRequest` (`category_id`, `tags.*`) and
`StoreNoteCategoryRequest` (`parent_id`) rules are scoped. The tests cover all three
paths and check for no side effects.

TASK-4: PASS
notes: I checked each genre rule against its original table: books→`library_books_genres`,
movies→`library_movies_genres`, games→`library_games_genres`,
music→`library_music_genres`. All four tables have `user_id` per their
migrations. `tags.*` is scoped for books, movies and games, and Music has no tags rule, as planned.
The tests cover a foreign genre for every media type and a foreign tag for books.

TASK-5: PASS
notes: `tags.*` is scoped in the link and quote Store/Update requests, and `category_id` in
`UpdateLibraryLinkRequest`. No `category_id` rule was added to `StoreLibraryLinkRequest`, which is correct: that is out of scope and
was mentioned in the notes. The tests cover a foreign tag/category on links and a foreign tag on quotes.

TASK-6: PASS
notes: `category_ids.*` is scoped against `time_tracking_categories` in both project
requests. The test asserts 422 and that no categories were synced.

TASK-7: NOT IMPLEMENTED (deferred, accepted)
notes: The task was gated on OQ-8, both in tasks.md and in the brief ("pending OQ-8").
`docs/STANDARDS.md:939` still lists OQ-8 as open. The developer skipped it and said so
in implementation.md. That is the behaviour tasks.md asked for, so it does not block this job.
`StoreDevRequestRequest.php:19` is unchanged and still accepts a client-chosen
`created_by_user_id`. It needs to be done once the owner answers OQ-8.

Scope: no unplanned source changes. The diff contains only the planned request/controller
rule changes, the tests, `.gitignore`, the zip deletion and job docs. The 7 remaining
`exists:` grep hits match the 6 the analyst explained plus the deferred OQ-8 rule.

Verification: the tests could not be run (the worktree has no `vendor/`). I checked statically
that every factory used has `forUser`, that every relation asserted on
(`tags`, `genres`, `categories`) exists on its model, that the route paths
match `routes/api.php`, and that the new imports (`Tag`, `LibraryGameGenre`,
`LibraryMovieGenre`, `Rule`) are present.

## Security

This change closes cross-tenant attach/reference via foreign ids on 34 rules. No new
exposure. The leaked export zip remains in public git history, and the owner has to decide on that (see
implementation.md).

## Overall

APPROVED

Nothing needs to change in the code. The only open item is a merge precondition: run
`composer test` (or the Todos/Notes/Libraries/TimeTracking test directories), because the
new tests have only been statically reviewed, not executed.
