# Implementation: Standards: larger cleanups

id: fix
developer: Claude (developer)
date: 2026-10-13

## Summary

OpenAPI drift fixed module by module (TASK-1 … TASK-13): only annotations
changed, no routes or controller behaviour. `OPENAPI_KNOWN_DRIFT` went from
80 entries to the 2 that the owner chose to keep (TASK-14), and the docs now
say so. OQ-4 is closed as "leave as is". Tenant-isolation tests were added to
all 11 API test files that had none (TASK-15 … TASK-25). **No isolation leak
was found**, so nothing is skipped or left out.

## Changes

TASK-1: `CheckInController.php`: `/check-ins` → `/check-in` (2 operations).
TASK-2: `ClipboardController.php`: `/clipboards…` → `/clipboard…` (5).
TASK-3: `TimeTrackingCategoryController.php`: `/time-tracking-categories…` → `/time-tracking/categories…` (4).
TASK-4: `TimeTrackingProjectController.php`: `/time-tracking-projects…` → `/time-tracking/projects…` (5).
TASK-5: `TimeTrackingEntryController.php`: `/time-tracking-entries…` → `/time-tracking/entries…`, including `start`, `{entry}/stop` and `statistics` (7). The parameter was already `{entry}`.
TASK-6: `Contacts/ContactController.php` + `Contacts/ImportController.php`: `/contacts/address-books…` → `/address-books…`, `/contacts` → `/address-books/contacts`, `/contacts/photos` → `/address-books/contacts/photos`, `{addressBookId}/{contactUri}` → `{addressBookId}/contacts/{contactUri}` (including `/photo`), and `/contacts/import/{start,select,state}` → `/address-books/import{,/select,/state}` (14).
  The "stray" `POST /contacts/address-books/{addressBookId}` turned out not to be stray. It is the annotation on `storeContact()`, whose route is `POST address-books/{addressBookId}/contacts` (`routes/api.php:224`). Its path was corrected, not deleted.
TASK-7: `FeedController.php`: subscription CRUD → `/feeds/subscriptions…`. `searchFeeds` changed from `@OA\Post` with a JSON body to `@OA\Get` with a required `search` query parameter, matching the GET handler (6).
TASK-8: `BudgetController.php`: `/finances/budgets…` → `/finances/budget…` (5).
TASK-9: `Calendars/CalendarController.php`: `widget/events` → `events/widget`, `occurrences/` → `occurrence/` (PUT + DELETE), `unsubscribe` changed to `@OA\Delete`, and invite accept/decline changed to `@OA\Put`. `Calendars/ImportController.php`: `/calendars/import/start` → `/calendars/import` (7).
TASK-10: `UserSettingsController.php`: `/user-settings/*` → `/users/me/settings/*`, and `complete-onboarding` changed to `@OA\Put` (11).
TASK-11: `AuthController.php`: `logoutAll` → `logout-all`, `revokeToken` → `revoke-token`. `UserController.php`: `me()` is now documented as `GET /users/me` (its `{id}` path parameter was removed because the path has no placeholder any more), `update-profile-image` → `me/profile-image`, and `{id}/public-profile` → `{user}/public-profile`. `FriendController.php`: accept changed from POST to `PUT …/{friendRequest}/accept`, and reject changed from `DELETE …/{id}` to `PUT …/{friendRequest}/reject`. `NotificationController.php`: `{id}/mark-read` → `{notification}/read`. `PushNotificationController.php`: `public-key` → `vapid-key`. `operationId`s are unchanged (9).
TASK-12: `TelegramBotConnectionController.php`: `token` → `token-request`, `connection` → `request`. `confirmToken()` was not touched (2 of 3).
TASK-13: `LibraryMovieController.php`: movie search `{query}` → `{title}`, both in the path and in the parameter name. `searchAlbumOnDeezer()` was not touched (1 of 2).
TASK-14: `tests/Unit/OpenApiSpecTest.php`: the constant now holds only the 2 kept entries and has a comment explaining why. The test docblock is rewritten so it no longer talks about "legacy paths". `docs/STANDARDS.md`: updated the intro item 1 and the section-2 intro, rewrote 2.2 (names the two methods, "Check: length = 2", and says explicitly that the constant is not empty or deleted), reworded 4.7 (OQ-4 decided), added a Decisions entry for OQ-4, and removed OQ-4 from Open questions. `docs/STANDARDS_BACKLOG.md`: the drift item is reduced to the 2 remaining entries, and the genre item is closed. `docs/TESTING.md` line 92 is updated.
TASK-15: `CalendarApiTest.php`: another user's `instanceId` is absent from `GET /calendars`. Update, destroy, list/store share and store event all return 404, which is this module's denial status (`calendarService->get()` is user-scoped). Update and destroy of an event in another user's calendar also return 404, the event is absent from that user's month list, and the owner's data stays unchanged.
TASK-16: `CheckInApiTest.php`: another user's check-ins are absent from `GET /check-in`, and storing on the same date leaves the other user's check-in untouched. Marks already had an isolation case.
TASK-17: `ContactApiTest.php`: another user's address book and contact are absent from `GET /address-books`, `GET /address-books/contacts` and `POST /address-books/contacts/photos`. Address book update/destroy, contact store/update/destroy and photo add/remove all return 404, which is this module's denial status.
TASK-18: `DevRequestApiTest.php`: a non-admin gets 403 on `PUT /dev-requests/{devRequest}` and the status is unchanged. Voting does not change another user's vote. Index and comments are shared by design (STANDARDS 12.3), so I made no isolation assertion on them.
TASK-19: `FeedApiTest.php`: another user's subscription returns 403 on show/update/destroy and is absent from `GET /feeds/subscriptions`. Items of feeds that only another user subscribes to are absent from `GET /feeds/items`. `feeds/friends` is not asserted.
TASK-20: `LibraryApiTest.php`: books return 403 on show/update/destroy (the index case already existed). Music returns 403 on show/update/destroy and is absent from the index. Book and music genres are absent from their index and return 403 on show/update/destroy.
TASK-21: `LibraryRestApiTest.php`: one case each for games, movies, links, quotes, recipes and plants: the item is absent from the index, show/update/destroy return 403, and the record is unchanged.
TASK-22: `TimeTrackingApiTest.php`: categories (absent from index, 403 on update/destroy), projects (absent from index, 403 on show/update/destroy), and entries (absent from index, 403 on update/stop/destroy). Storing or starting an entry on another user's project returns 403. `statistics` leaves out another user's entries.
TASK-23: `NotificationApiTest.php`: another user's notification is absent from `GET /notifications`. `PUT …/{notification}/read` returns 404 (`findOrFail` on the user's own notifications), and `read-all` leaves it unread.
TASK-24: `WeatherTest.php`: `GET /weather/today` uses the authenticated user's coordinates, city and unit. The HTTP calls are faked, and the test asserts that the other user's latitude is never requested. An unconfigured user does not fall back to another user's location, and no HTTP call is made.
TASK-25: `ExportTest.php`: another user's completed export (with its file present) returns 404 on download, while the owner gets 200. `GET /export/status` does not report another user's job (`data: null`).

Brief item 4 (factory/model tests): no `<Module>Test.php` factory/model file was touched, so there was nothing to prune.

Verification: `php artisan test tests/Unit/OpenApiSpecTest.php` was green after every drift task. Every touched test file is green. Full suite: 11 failures, all pre-existing (see below), none of them new.

## Known issues / follow-ups

- **Pre-existing red tests (not caused by this job):** 11 failures in `Libraries/Tests/GrabReleasesTest.php` (8) and `Users/Tests/JobTest.php` (3, `ScaleCovers`). All are `ArgumentCountError`: `ScaleCovers::handle()` (and the `Grab*Releases` jobs) now take a third `LibraryReleases` argument that the tests don't pass. They already failed before TASK-1.
- **Environment:** the worktree had no `vendor/` and no `storage/` directories. I ran `composer install --no-scripts` (the locked dependencies only, no new packages) and created the git-ignored `storage/framework/*` and `storage/logs` directories so the suite could run. There is no `.env`, so every test emits a dotenv "file not found" warning. `.env.testing` shows as modified in `git status`, but git can't hash it ("unsupported file type", probably a mount). I didn't create or change it, and I deliberately didn't commit it.
- `TimeTrackingEntryController::statistics()` validates `category_id` with a bare `exists:time_tracking_categories,id` (rule 3.2). No data leaks, because entries are scoped by user and a user's projects can't be linked to a foreign category. It isn't listed in the backlog's rule-3.2 item, though.
- `Route::apiResource` registers `GET` show routes for `time-tracking/categories` and `time-tracking/entries`, but neither controller has a `show()` method. I didn't assert on them.
- `LibraryMovieController::search()` names its argument `$query`, while the route placeholder is `{title}`. It works because Laravel passes the route parameter by position. I left it alone because only the annotation was in scope.
- The backlog item "Tenant-isolation tests for the 11 API test files" is still unticked in `STANDARDS_BACKLOG.md`. TASK-14 only covered the drift and genre items, so ticking it is left to the owner or reviewer.
- The two remaining drift entries (`confirmToken()` and `searchAlbumOnDeezer()`) still need an owner decision: either give each a route or delete it.
