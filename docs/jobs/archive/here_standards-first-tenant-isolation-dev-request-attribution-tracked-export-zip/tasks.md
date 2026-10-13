# Tasks: Standards: first (tenant isolation, dev-request attribution, tracked export zip)

Branch verified: worktree HEAD is
`feature/here_standards-first-tenant-isolation-dev-request-attribution-tracked-export-zip`,
which matches `brief.md`.

## Background (verified by reading the code, 2026-10-13)

- Every table these rules point at has a `user_id` uuid column: `tags`,
  `todo_categories`, `note_categories`, `library_{books,movies,games,music}_genres`,
  `library_links_categories`, `time_tracking_categories`. So the fix from
  `STANDARDS.md` 3.2 works on all of them without schema changes:
  `Rule::exists('<table>', 'id')->where('user_id', $this->user()->id)`
  (in FormRequests), or `$request->user()->id` for inline `$request->validate()`
  in a controller. No migration is needed.
- The scoped rule rejects another user's id with **422**. That is the status
  the tests should expect (`TESTING.md` says 422).
- Turning a pipe string into an array rule needs `use Illuminate\Validation\Rule;`.
  Keep the other parts of the rule unchanged (`nullable`, `sometimes`, …).
- Right now there are 40 hits for the brief's grep. This job covers 34 of them.
  For the other 6, see "Out of scope / open questions" below.

Each tenant-isolation task is one module and one commit, as the brief allows.
Each one adds at least one API test that posts **another user's** id and
expects 422. Where you can do it cheaply, also assert that nothing was
stored or attached. Follow the existing isolation-test shape
(`app/Api/Todos/Tests/TodoApiTest.php:46-59`) and `docs/TESTING.md`.

---

### TASK-1: Remove the tracked user-data export zip and ignore `tmp/`
- Description: `git rm` the file `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip` and add `/tmp/` to `.gitignore`.
- Files: `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip` (delete), `.gitignore`
- Dependencies: none
- Risk: low. It only touches the file tree. Do **not** rewrite history: the brief says that is the owner's call (the repo is public), so record it as an open item in the implementation notes. Do not open or print the zip's contents. It is user data.

### TASK-2: Scope Todos foreign ids (category, tags, workspace categories)
- Description: Change these to `Rule::exists(...)->where('user_id', …)`: `category_id` and `tags.*` in `StoreTodoRequest` and `UpdateTodoRequest`, `categories.*` in `StoreTodoWorkspaceRequest`, and the inline `categories.*` rules in `TodoWorkspaceController::attachCategories`/`detachCategories`. Then add isolation tests.
- Files: `app/Api/Todos/Requests/StoreTodoRequest.php:20,22`, `app/Api/Todos/Requests/UpdateTodoRequest.php:21,23`, `app/Api/Todos/Requests/StoreTodoWorkspaceRequest.php:14`, `app/Api/Todos/Controllers/TodoWorkspaceController.php:273,335`, `app/Api/Todos/Tests/TodoApiTest.php`
- Dependencies: none
- Risk: medium. Todos is the reference module and is used heavily. A client that currently sends ids it doesn't own (for example after a category was deleted and recreated) would now get 422. That is the intended behaviour, but it is user-visible.

### TASK-3: Scope Notes foreign ids (category, tags, category parent)
- Description: Scope `category_id` and `tags.*` in `StoreNoteRequest` and `UpdateNoteRequest`, and `parent_id` in `StoreNoteCategoryRequest`. Add isolation tests (another user's category, tag and parent category → 422).
- Files: `app/Api/Notes/Requests/StoreNoteRequest.php:14,16`, `app/Api/Notes/Requests/UpdateNoteRequest.php:14,16`, `app/Api/Notes/Requests/StoreNoteCategoryRequest.php:13`, `app/Api/Notes/Tests/NoteApiTest.php`
- Dependencies: none
- Risk: low. These are mechanical rule changes on three requests, and the column layout has been verified.

### TASK-4: Scope Libraries genres and tags on books, movies, games, music
- Description: Scope `genres.*` (against each type's own genre table) and `tags.*` in the Store and Update requests for Books, Movies and Games, and `genres.*` in the Store and Update requests for Music (Music has no `tags.*` rule). Add isolation tests (at least one per media type for genres, plus one for tags).
- Files: `app/Api/Libraries/Requests/Books/{Store,Update}LibraryBookRequest.php:29,31`, `app/Api/Libraries/Requests/Movies/{Store,Update}LibraryMovieRequest.php:22,24`, `app/Api/Libraries/Requests/Games/{Store,Update}LibraryGameRequest.php:26,28`, `app/Api/Libraries/Requests/Music/{Store,Update}LibraryMusicRequest.php:25`, plus whichever of `app/Api/Libraries/Tests/LibraryApiTest.php` / `LibraryRestApiTest.php` already covers these endpoints
- Dependencies: none
- Risk: medium. That is 8 files and 14 rules, with four nearly identical genre tables, so it is easy to point one rule at the wrong genre table. Check each table name against the original rule.

### TASK-5: Scope Libraries links and quotes (tags, link category)
- Description: Scope `tags.*` in `StoreLibraryLinkRequest`, `UpdateLibraryLinkRequest`, `StoreLibraryQuoteRequest` and `UpdateLibraryQuoteRequest`, and `category_id` in `UpdateLibraryLinkRequest`. Add isolation tests.
- Files: `app/Api/Libraries/Requests/Links/StoreLibraryLinkRequest.php:17`, `app/Api/Libraries/Requests/Links/UpdateLibraryLinkRequest.php:17,18`, `app/Api/Libraries/Requests/Quotes/{Store,Update}LibraryQuoteRequest.php:17`, the Libraries API test file covering links and quotes
- Dependencies: TASK-4 if both go into the same Libraries commit; otherwise none
- Risk: low. These are mechanical. Note: `StoreLibraryLinkRequest` has **no** `category_id` rule today. Do not add one; that would be scope creep. Mention it in the notes if it looks like a gap.

### TASK-6: Scope TimeTracking project `category_ids.*`
- Description: Scope `category_ids.*` against `time_tracking_categories` in `StoreTimeTrackingProjectRequest` and `UpdateTimeTrackingProjectRequest`. Right now `TimeTrackingService::createProject/updateProject` (`:203-207`, `:219-225`) `sync()` them unchecked. Add isolation tests.
- Files: `app/Api/TimeTracking/Requests/StoreTimeTrackingProjectRequest.php:16`, `app/Api/TimeTracking/Requests/UpdateTimeTrackingProjectRequest.php:16`, `app/Api/TimeTracking/Tests/TimeTrackingApiTest.php`
- Dependencies: none
- Risk: low. These are two mechanical rules.

### TASK-7: Take dev-request attribution from the authenticated user (**gated on OQ-8**)
- Description: Remove the `created_by_user_id` rule from `StoreDevRequestRequest`. In `DevRequestController::store`, pass `$request->user()` to `DevRequestService::create` and set `created_by_user_id` from it there. Remove `created_by_user_id` from the `@OA` request body (`DevRequestController.php:70`). Add a test that a client-supplied `created_by_user_id` for another user is ignored and the request is attributed to the caller.
- Files: `app/Api/DevRequests/Requests/StoreDevRequestRequest.php:19`, `app/Api/DevRequests/Services/DevRequestService.php:21-27`, `app/Api/DevRequests/Controllers/DevRequestController.php:70,93`, `app/Api/DevRequests/Tests/DevRequestApiTest.php`. `DevRequestTest.php:80,163` set the field directly on the model or factory and probably need no change. Check them.
- Dependencies: none (technically). It is blocked on the owner's answer to OQ-8.
- Risk: medium. `STANDARDS.md` OQ-8 is still open: it asks whether "admins filing on behalf of users" is intended. The route has no admin gate (`routes/api.php:303-305`, any authenticated user), so the current behaviour looks like an oversight. But the brief itself says "pending OQ-8". **The developer should confirm OQ-8 is answered (or that the brief's wording counts as the answer) before implementing it.** If not, leave this task out and say so in the implementation notes.

---

## Out of scope / open questions

1. **The brief's check target ("0 unscoped") can't be reached literally within the listed scope.** After TASK-2…7, 6 grep hits remain. None are in the brief's file list:
   - `TimeTracking/Requests/StoreTimeTrackingEntryRequest.php:17` and `TimeTrackingEntryController.php:164,267` (`project_id`): these are already re-scoped in the controller (`:91-93`, `:167-170`, `:271-273`). Rule 3.2 counts that as compliant ("names a service line that re-scopes it"), but the plain grep still counts them.
   - `TimeTrackingEntryController.php:393` (`category_id` on statistics): the query is already `forUser` (`TimeTrackingService.php:112`), so another user's id returns empty stats, not their data. This is effectively scoped.
   - `Calendars/Requests/ShareCalendarRequest.php:14` (`friend_id` → `users`): open question **OQ-7**, an owner decision.
   - `Users/Requests/StoreFriendRequestRequest.php:12` (`receiver_id` → `users`): a friend request has to target other users, so the bare `exists:users` is correct.

   Recommendation: leave all six untouched in this job, and record the final count (6, all explained) in the implementation notes instead of rewriting them just to satisfy the grep. If the owner wants a literal 0, that is a follow-up.
2. **Git history rewrite** for the export zip: this is the owner's decision (see TASK-1). Not part of this job.
3. Ticking the checkboxes in `docs/STANDARDS_BACKLOG.md` / `STANDARDS.md` 3.2 "Currently 40 hits, 0 scoped" is not explicitly requested. Only update them if the pipeline's convention expects it.
