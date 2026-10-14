# Tasks: Standards: larger cleanups

id: fix
analyst: Claude (analyst)
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

## Scope decisions (owner, 2026-10-13)

- Brief item 1 (OpenAPI drift): **go ahead**: TASK-1 … TASK-14.
- Brief item 2 (Libraries genre CRUD ×4, OQ-4): **libraries stay as is**. No task here.
  Recording the decision in `STANDARDS.md` (closing OQ-4) is in TASK-14.
- Brief item 3 (tenant-isolation tests): **go ahead**: TASK-15 … TASK-25.
- Brief item 4 (factory/model tests): **agreed**. No standalone task. Rule for every
  task: if a `<Module>Test.php` factory/model file is touched anyway, keep the
  relation/scope cases and drop "creates a valid X" / "has correct fillable
  attributes" (`TESTING.md`). Do not touch those files only for this.

## Rules that apply to every drift task (TASK-1 … TASK-13)

- **Change annotations only.** Do not change `routes/api.php`, controller signatures or behaviour.
  The route table is the source of truth (rule 2.2). The frontend uses the routes, not the spec.
- `OpenApiSpecTest` compares drift to `OPENAPI_KNOWN_DRIFT` **exactly**. So every task
  must remove its fixed entries from the constant in the same change, or the
  test fails. Each task ends with `php artisan test tests/Unit/OpenApiSpecTest.php`
  green.
- When the path changes, rename the `@OA\Parameter(name=…)` so it matches the route
  placeholder (e.g. `{id}` → `{friendRequest}`).
- Do not touch the rest of the docblock (envelope refs, rule 2.3 etc.). That is a separate backlog item.

## Task breakdown

TASK-1: Fix the check-in annotations: `/check-ins` → `/check-in` (2 entries).
  files: app/Api/CheckIn/Controllers/CheckInController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path rename in docblocks only.

TASK-2: Fix the clipboard annotations: `/clipboards…` → `/clipboard…` (5 entries).
  files: app/Api/Clipboard/Controllers/ClipboardController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path rename in docblocks only.

TASK-3: Fix the time-tracking category annotations: `/time-tracking-categories…` → `/time-tracking/categories…` (4 entries).
  files: app/Api/TimeTracking/Controllers/TimeTrackingCategoryController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path rename in docblocks only.

TASK-4: Fix the time-tracking project annotations: `/time-tracking-projects…` → `/time-tracking/projects…` (5 entries).
  files: app/Api/TimeTracking/Controllers/TimeTrackingProjectController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path rename in docblocks only.

TASK-5: Fix the time-tracking entry annotations: `/time-tracking-entries…` → `/time-tracking/entries…`, including `start`, `{entry}/stop`, `statistics` (7 entries).
  files: app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path rename in docblocks only. Check that `/time-tracking/entries/{entry}` uses the
  `{entry}` placeholder (`routes/api.php:397`).

TASK-6: Fix the address-book/contact annotations: `/contacts/address-books…` → `/address-books…`, `/contacts` → `/address-books/contacts`, `/contacts/photos` → `/address-books/contacts/photos`, `{addressBookId}/{contactUri}` → `{addressBookId}/contacts/{contactUri}`, `/contacts/import/{start,select,state}` → `/address-books/import{,/select,/state}`. Delete the documented `POST /contacts/address-books/{addressBookId}` only if no route exists for it (check first) (14 entries).
  files: app/Api/Contacts/Controllers/ContactController.php, app/Api/Contacts/Controllers/ImportController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: medium. Biggest group. `POST address-books/{addressBookId}` has no matching route.
  The developer must find out which method carries it and report it if it turns out to be a stray
  operation, not delete it silently. Annotations only; DAV routes and `pgsql` are not touched.

TASK-7: Fix the feed annotations: `/feeds` CRUD → `/feeds/subscriptions…`, `POST /feeds/search` → `GET /feeds/search` (6 entries).
  files: app/Api/Feeds/Controllers/FeedController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path and verb in docblocks. Check whether `search` documents a request body; if it does, change it to
  a query parameter so it matches the GET handler.

TASK-8: Fix the budget annotations: `/finances/budgets…` → `/finances/budget…` (route is singular, `routes/api.php:328`) (5 entries).
  files: app/Api/Finances/Controllers/BudgetController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path rename in docblocks only. Placeholder is `{budget}`.

TASK-9: Fix the calendar annotations: `/calendars/widget/events` → `/calendars/events/widget`, `occurrences/` → `occurrence/` (PUT+DELETE), `POST {instanceId}/unsubscribe` → `DELETE`, invites `POST …/accept|decline` → `PUT`, `/calendars/import/start` → `/calendars/import` (7 entries).
  files: app/Api/Calendars/Controllers/CalendarController.php, app/Api/Calendars/Controllers/ImportController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Verb/path changes in docblocks only. The DAV routes in `routes/web.php` are not involved.

TASK-10: Fix the user-settings annotations: `/user-settings/*` → `/users/me/settings/*`, `complete-onboarding` `POST` → `PUT` (11 entries).
  files: app/Api/Users/Controllers/UserSettingsController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Path prefix and one verb in docblocks.

TASK-11: Fix the auth/users/friends/notifications annotations: `auth/logoutAll` → `auth/logout-all`, `auth/revokeToken` → `auth/revoke-token`, `GET /users/{id}` (on `me()`) → `GET /users/me`, `POST /users/update-profile-image` → `POST /users/me/profile-image`, `/users/{id}/public-profile` → `/users/{user}/public-profile`, `DELETE /friends/requests/{id}` → `PUT /friends/requests/{friendRequest}/reject`, `POST /friends/requests/{id}/accept` → `PUT /friends/requests/{friendRequest}/accept` (check the verb in the docblock), `PUT /notifications/{id}/mark-read` → `PUT /notifications/{notification}/read`, `GET /notifications/push/public-key` → `GET /notifications/push/vapid-key` (9 entries).
  files: app/Api/Users/Controllers/AuthController.php, app/Api/Users/Controllers/UserController.php, app/Api/Users/Controllers/FriendController.php, app/Api/Users/Controllers/NotificationController.php, app/Api/Notifications/Controllers/PushNotificationController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Docblocks only, but five files. Keep `operationId`s unchanged (only `userShow` might
  read better as `userMe`; leave it unless asked).

TASK-12: Fix the Telegram annotations: `GET /telegram/token` → `GET /telegram/token-request`, `GET /telegram/connection` → `GET /telegram/request` (2 of 3 entries). **Leave `POST /telegram/confirm-token` completely untouched**: method and annotation stay, and the entry stays in the constant (Q1).
  files: app/Api/Telegram/Controllers/TelegramBotConnectionController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. Docblock renames only. `confirmToken()` is out of scope by owner decision.

TASK-13: Fix the Libraries annotation: movie search `{query}` → `{title}` (`routes/api.php:281`) (1 of 2 entries). **Leave `searchAlbumOnDeezer()` and its `GET /libraries/music/search/deezer/{artist}/{album}` annotation completely untouched**; the entry stays in the constant (Q1).
  files: app/Api/Libraries/Controllers/LibraryMovieController.php, tests/Unit/OpenApiSpecTest.php
  depends: none
  risk: low. One parameter rename in a docblock.

TASK-14: Shrink `OPENAPI_KNOWN_DRIFT` to the two entries kept by owner decision (`POST api/v1/telegram/confirm-token`, `GET api/v1/libraries/music/search/deezer/{artist}/{album}`). **Do not delete the constant.** Add a short comment saying why those two remain, and rewrite the test docblock (lines 6-15) so it no longer calls the list "legacy paths". Update the docs: `STANDARDS.md` 2.2 (80 → 2, "Check" line, and name the two methods) and the intro count at line ~183; close OQ-4 as "leave as is" (move to `## Decisions`, adjust rule 4.7 wording); update `STANDARDS_BACKLOG.md` (drift item reduced to the 2 kept entries; genre item closed); update `TESTING.md` line ~92.
  files: tests/Unit/OpenApiSpecTest.php, docs/STANDARDS.md, docs/STANDARDS_BACKLOG.md, docs/TESTING.md
  depends: TASK-1 … TASK-13
  risk: low. Mechanical. The brief's "until the constant is empty, then delete it" is consciously
  not met; the doc wording must say so rather than claim it is done.

TASK-15: Add tenant-isolation tests to `CalendarApiTest`: another user's `instanceId` gives 403 (or the module's existing denial status) on update/destroy/share/event writes, and the calendar is absent from `GET /calendars`.
  files: app/Api/Calendars/Tests/CalendarApiTest.php
  depends: none
  risk: medium. DAV-backed (SabreDAV SQLite file shared per process, `TESTING.md`), so setup goes through
  the existing helpers/factories, and a test that commits through SabreDAV must clean up.

TASK-16: Add tenant-isolation tests to `CheckInApiTest`: another user's check-ins are absent from `GET /check-in` and `GET /check-in/marks`.
  files: app/Api/CheckIn/Tests/CheckInApiTest.php
  depends: none
  risk: low. Index-only resource (no show/update/destroy routes).

TASK-17: Add tenant-isolation tests to `ContactApiTest`: another user's `addressBookId` gives 403 on update/destroy/contact writes and is absent from `GET /address-books` and `GET /address-books/contacts`.
  files: app/Api/Contacts/Tests/ContactApiTest.php
  depends: none
  risk: medium. DAV-backed, same caveats as TASK-15.

TASK-18: Add tenant-isolation tests to `DevRequestApiTest`: another user's dev request gives 403 on `PUT /dev-requests/{devRequest}`. Index/vote/comments are probably shared on purpose; check the controller and only assert what is meant to be private.
  files: app/Api/DevRequests/Tests/DevRequestApiTest.php
  depends: none
  risk: medium. Dev requests look public by design. Asserting isolation that the product does not intend
  would be wrong; ask if unclear.

TASK-19: Add tenant-isolation tests to `FeedApiTest`: another user's subscription gives 403 on show/update/destroy and is absent from `GET /feeds/subscriptions` and `GET /feeds/items`.
  files: app/Api/Feeds/Tests/FeedApiTest.php
  depends: none
  risk: low. Standard owned resource. `feeds/friends` is shared by design; do not assert on it.

TASK-20: Add tenant-isolation tests to `LibraryApiTest` (books, music): another user's entry gives 403 on show/update/destroy and is absent from index.
  files: app/Api/Libraries/Tests/LibraryApiTest.php
  depends: none
  risk: low. Same pattern as `TodoApiTest.php:46-59`.

TASK-21: Add tenant-isolation tests to `LibraryRestApiTest` (games, movies, links, quotes, recipes, plants): another user's entry gives 403 on show/update/destroy and is absent from index. One case per resource.
  files: app/Api/Libraries/Tests/LibraryRestApiTest.php
  depends: none
  risk: low. Repetitive but simple. Six resources, so this is the largest test task.

TASK-22: Add tenant-isolation tests to `TimeTrackingApiTest` (categories, projects, entries): another user's record gives 403 on show/update/destroy (and `stop` for entries) and is absent from index and `statistics`.
  files: app/Api/TimeTracking/Tests/TimeTrackingApiTest.php
  depends: none
  risk: low. Standard owned resources.

TASK-23: Add tenant-isolation tests to `NotificationApiTest`: another user's notification gives 403/404 on `PUT /notifications/{notification}/read`, is absent from `GET /notifications`, and `read-all` does not touch it.
  files: app/Api/Notifications/Tests/NotificationApiTest.php
  depends: none
  risk: low. Assert whichever denial status the controller is meant to return; do not change the controller.

TASK-24: Add a tenant-isolation test to `WeatherTest`: `GET /weather/today` uses the authenticated user's city/unit settings, not another user's.
  files: app/Api/Weather/Tests/WeatherTest.php
  depends: none
  risk: low. No owned record; the test is about which user's settings are used. Fake the HTTP calls.

TASK-25: Add tenant-isolation tests to `ExportTest`: another user's export id cannot be downloaded (`GET /export/{id}/download`; the controller scopes via `forUser`, so expect 404), and `GET /export/status` does not report another user's job.
  files: app/Api/Export/Tests/ExportTest.php
  depends: none
  risk: low. Scoping already exists (`ExportController.php:57,87`).

## Rule for the isolation tasks (TASK-15 … TASK-25)

Use `makeUser()`, `->forUser($other)`, `actingAs($user, 'sanctum')`, `expectApiError($response, 403)`
(`TESTING.md`). If a test shows that isolation is **not** enforced, **stop and report** it
(see Q2). Do not fix the controller inside this job.

## Resolved questions (owner, 2026-10-13)

- **Q1: dead controller methods.** `TelegramBotConnectionController::confirmToken()`
  and `LibraryMusicController::searchAlbumOnDeezer()`: **leave them completely**.
  Neither the methods nor their annotations are touched. Their two drift entries stay
  in `OPENAPI_KNOWN_DRIFT` (see TASK-12, TASK-13, TASK-14).
- **Q2: isolation leaks.** If an isolation test reveals a real leak: **report only**.
  Record it in `implementation.md` under "Known issues / follow-ups"; do not change the
  controller/service in this job. The failing case must not be committed as a red test. Mark it
  `->skip('isolation leak, see implementation.md')` or leave it out, and report which.
