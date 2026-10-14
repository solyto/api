# Verdict: Standards: larger cleanups

id: fix
reviewer: Claude (reviewer)
date: 2026-10-13

## Review

Reviewed `git diff main...HEAD` (base `main` from `.manigot/manigot.json`) on
branch `feature/fix_standards-larger-cleanups`. Ran `OpenApiSpecTest` plus all
11 touched API test files: all pass (the warnings are the known missing-`.env`
dotenv notices, not failures).

TASK-1: PASS
notes: `CheckInController.php`: both operations now use `/check-in`.

TASK-2: PASS
notes: `ClipboardController.php`: 5 operations now use `/clipboard…`.

TASK-3: PASS
notes: `TimeTrackingCategoryController.php`: 4 operations now use `/time-tracking/categories…`.

TASK-4: PASS
notes: `TimeTrackingProjectController.php`: 5 operations now use `/time-tracking/projects…`.

TASK-5: PASS
notes: `TimeTrackingEntryController.php`: 7 operations, including `start`, `{entry}/stop` and `statistics`. The placeholder is `{entry}`.

TASK-6: PASS
notes: `ContactController.php` and `Contacts/ImportController.php`: 14 operations. The supposed stray `POST …/{addressBookId}` is `storeContact()`. It was correctly re-pathed to `POST /address-books/{addressBookId}/contacts` instead of being deleted, and `implementation.md` reports this as the task asked. No DAV routes or `pgsql` changes.

TASK-7: PASS
notes: `FeedController.php`: subscription CRUD now uses `/feeds/subscriptions…`. `searchFeeds` changed from Post with a body to Get with a `search` query parameter, matching the handler.

TASK-8: PASS
notes: `BudgetController.php`: 5 operations now use `/finances/budget…` with `{budget}`.

TASK-9: PASS
notes: `CalendarController.php`: `events/widget`, `occurrence/` (PUT and DELETE), unsubscribe → Delete, invite accept/decline → Put. `Calendars/ImportController.php` now uses `/calendars/import`. All of these match `routes/api.php`.

TASK-10: PASS
notes: `UserSettingsController.php`: 11 operations now use `/users/me/settings/*`, and `complete-onboarding` is Put.

TASK-11: PASS
notes: The changes are spread over five files. `operationId`s are unchanged. In `UserController::me()`, the `{id}` path parameter was removed. This is a necessary consequence of the path having no placeholder, so it is not scope creep. Parameter names now match `{friendRequest}`, `{notification}` and `{user}`.

TASK-12: PASS
notes: `token-request` and `request` are fixed. `confirmToken()` is untouched.

TASK-13: PASS
notes: `{query}` is now `{title}` in both the path and the parameter name. `searchAlbumOnDeezer()` is untouched.

TASK-14: PASS
notes: The constant holds exactly the 2 kept entries, with an explanatory comment, and the test docblock is rewritten. `STANDARDS.md` is updated: the intro, the section-2 intro, 2.2 (names both methods, "Check: length = 2", and states that the constant is not emptied or deleted), 4.7, an OQ-4 Decisions entry, and OQ-4 removed from Open questions. `STANDARDS_BACKLOG.md` and `TESTING.md` are updated accordingly.

TASK-15: PASS
notes: The module's denial status is 404 (service-scoped lookup), and it is documented in the test comment. The tests cover index absence, update/destroy/share list/share store/event store, and event update/destroy, and they check that the owner's data is unchanged. DAV writes go through HTTP and are covered by the per-test `pgsql` transaction.

TASK-16: PASS
notes: The other user's check-ins are absent from the index, and a same-date store does not touch them. A marks case already existed.

TASK-17: PASS
notes: The tests cover index and contacts list absence, empty photo lookup, 404 on all address-book, contact and photo writes, and an unchanged owner contact.

TASK-18: PASS
notes: The tests check 403 for a non-admin update and that voting does not touch another user's vote. Listing and comments are left alone as shared by design, which is correct per the task.

TASK-19: PASS
notes: The tests check 403 on show/update/destroy, absence from subscriptions and items, and that `feeds/friends` is not asserted.

TASK-20: PASS
notes: Books and music are covered, plus their genres.

TASK-21: PASS
notes: There is one case each for games, movies, links, quotes, recipes and plants (index, show, update, destroy, and the record unchanged).

TASK-22: PASS
notes: Categories, projects and entries (including stop) are covered, as are store/start against a foreign project and statistics.

TASK-23: PASS
notes: The tests check index absence, 404 on `read`, and that `read-all` leaves the other user's notification unread.

TASK-24: PASS
notes: HTTP is faked. The tests assert the user's own coordinates and unit, and that there is no fallback to another user's location.

TASK-25: PASS
notes: The tests check 404 on a foreign download (and 200 for the owner), and that status does not report another user's job.

Scope: no changes outside tasks.md apart from job docs and `tokens.json`. The
uncommitted `.env.testing` modification is not part of the branch. Brief item 4
(factory/model tests) needed no work because no such file was touched.

## Security

Not run.

## Overall

APPROVED

No blockers.
