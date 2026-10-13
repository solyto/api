# Report: Standards audits (backlog "Audits" section)

date: 2026-10-13
branch: `feature/force_standards-audits` (job `force`), tree at `19f0849`
scope: The five items under "Audits (read, then decide per hit)" in `docs/STANDARDS_BACKLOG.md`, judged against `docs/STANDARDS.md`. Written for the owner, who makes the decisions. No application code, routes, config, tests, `STANDARDS.md` or `STANDARDS_BACKLOG.md` were changed. Fixes go to a follow-up job.

Each finding gives file:line, the rule it is judged against, a verdict and a recommendation. Verdicts:

- **OK**: matches the rule.
- **violation**: breaks the rule as written.
- **owner decision**: the standard leaves the call to the owner, or the owner's reference module (`app/Api/Todos/`) disagrees with the rule (STANDARDS.md "Decisions", first bullet).

## Summary

| # | Item | Rule | Verdict | Recommended follow-up | Owner decision? |
|---|---|---|---|---|---|
| A1 | 21 inline `$request->validate()` sites | 3.1 | 13 OK. **1 violation**: `NotificationSettingsController::update`, 36 fields, missed by the backlog. 7 owner decision: 2 `update` actions with ≤ 3 fields (`TodoController::updateSubtask`, `PasskeyController::update`) and the `url` shape repeated ×5 in the Libraries imports. | Add `UpdateNotificationSettingsRequest`. Add `UpdateTodoSubtaskRequest` + `UpdatePasskeyRequest` if the rule is kept. Reword the "exists nowhere else" clause for one-field rules. | **yes**: the `update` question (reference module involved) and the `url` ×5 question |
| A2 | 14 jobs on default `$tries`/`$timeout` | 9.2 | 6 need numbers, 8 are fine on defaults | `Grab{Music,Book,Movie}Releases`, `ScaleCovers`: `$tries = 1`, `$timeout = 300`. `ImportCalendars`/`ImportContacts`: `$tries = 1`, timeout per the decision. All timeouts < `retry_after` 360. | **yes**: the import jobs' 1800 s loop budget vs `retry_after` 360 (short timeout, or a separate queue connection). DAV `pgsql` writes flagged. |
| A3 | `ShareCalendarRequest` `friend_id` (OQ-7) | 3.3 / 3.4, OQ-7 | OQ-7's premise is wrong: `after()` (`:18-41`) already restricts sharing to friends (422), and has since the initial commit | Close OQ-7. Add two sharing tests (non-friend 422, friend 200). Optionally drop the dead 404 branch. | **yes**: accept `after()` for cross-record validation (recommended) or move the check |
| A4 | Methods over 60 lines outside DAV | (backlog criterion) | All six re-measured, lengths unchanged. All **keep**. `TodoService::parse` has 2 callers, but both use the whole method. | None in code. Reword the criterion to "a second caller needs *part* of it". | no |
| A5 | Route names `->name('x.')` | 1.4 | 11 groups + 1 route (not 7). No callers, confirmed. But the group names keep the `apiResource('/')` route names unique. | **Keep**. Drop only `->name('uploadCover')`. Correct rule 1.4's wording. Verify with `route:list` + `uniq -d`. | **yes** (rule says owner picks); recommendation: keep |

None of the five items needed the owner's approval to audit, and nothing was changed in code.

## Method

Read-only. All counts and line numbers were taken on this branch at `19f0849` (2026-10-13), after `ca1627b` "Standards: rewrites". Commands, run from the repository root:

- A1: `grep -rn '\$request->validate(' app`, then each hit read in full and mapped to its route in `routes/api.php`. Also `grep -rn "Validator::make" app` for validation outside controllers.
- A2: `ls app/Api/*/Jobs/*.php`; `grep -n "tries\|timeout" app/Api/*/Jobs/*.php`; `routes/console.php`; `grep -rn "::dispatch" app routes`; `config/queue.php:44,54` (`retry_after`).
- A3: `ShareCalendarRequest`, `CalendarController::shareCalendar`, `CalendarService::share`, `Friend`, `User::friends`; `git log -S` for history; a test grep for coverage.
- A4: tokenizer-based method length script (described in A4) over `app/` minus `app/Dav/`; `grep -rn` for each method's callers.
- A5: `grep -n -e "->name(" routes/*.php`; caller grep over `app tests resources bootstrap config routes database` for `route(`, `to_route(`, `URL::route`, `->route(`, `routeIs(`, `Route::has(`.

Limits: `vendor/` is not installed in this worktree, so nothing was executed through artisan (`route:list`, tests). The A5 route-name collision is derived from framework source and comes with a verification command. Worker flags (`--tries`, `--timeout`) and whether `route:cache` runs in production are set in the separate deployment repo and could not be checked.

## A1: Inline `$request->validate()` (rule 3.1)

Rule 3.1 and the matching Decision allow inline validation for **≤ 3 fields on a child or action endpoint whose shape exists nowhere else**, and **never on `update`**. "Fields" below means distinct input names: `x` and `x.*` count as one field. Whether an action is an `update` is judged by both the method name and the route verb in `routes/api.php`.

**Drift:** there are **21** sites, not 22. `TimeTrackingEntryController.php:158-165`, the six-field case named in rule 3.1 and in the backlog, no longer exists. Commit `ca1627b` ("Standards: rewrites") added `UpdateTimeTrackingEntryRequest`, and `update` (`:155`) now takes it. The two remaining hits in that file are `:260` (`start`) and `:384` (`statistics`). The backlog's "Rewrites" item for this is still unticked.

**New finding:** the backlog says every remaining site has ≤ 3 fields. That is wrong. `NotificationSettingsController::update` validates **36 fields** inline (`:107-144`), probably missed because the rule array spans many lines.

| # | Site | Action | Route | Fields | `update`? | Same shape elsewhere? | Verdict |
|---|---|---|---|---|---|---|---|
| 1 | `Calendars/Controllers/CalendarController.php:160` | `updateCalendarsOrder` | `PUT calendars/order` (`api.php:187`) | 1 (`order`, `order.*`) | no: reorder action, not a resource update | no | OK |
| 2 | `Calendars/Controllers/EventAttachmentController.php:136` | `attachTodo` | `POST calendars/events/{eventId}/attachments/todos` (`:179`) | 1 | no | no (`todo_id`) | OK. The id is then re-scoped via `forUser` (rule 1.2 example) |
| 3 | `Calendars/Controllers/EventAttachmentController.php:232` | `attachNote` | `POST …/attachments/notes` (`:182`) | 1 | no | no (`note_id`) | OK |
| 4 | `CheckIn/Controllers/CheckInController.php:75` | `marks` | `GET check-in/marks` (`:153`) | 2 (`from`, `to`) | no, read | **yes**, identical to #12 including the 366-day check | OK under 3.1 (read endpoint). See note (b) |
| 5 | `Feeds/Controllers/FeedController.php:207` | `searchFeeds` | `GET feeds/search` (`:334`) | 1 | no, read | no | OK |
| 6 | `Feeds/Controllers/FeedController.php:279` | `testFeed` | `POST feeds/test` (`:336`) | 1 (`url`) | no | same `url` rule as #7-11 | OK. See note (c) |
| 7 | `Libraries/Controllers/LibraryBookController.php:419` | `import` | `POST libraries/books/import/{service}` (`:258`) | 1 (`url`) | no | **yes**, ×5 (#7-11) | owner decision, note (c) |
| 8 | `Libraries/Controllers/LibraryGameController.php:343` | `import` | `POST libraries/games/import/{service}` (`:292`) | 1 | no | yes | as #7 |
| 9 | `Libraries/Controllers/LibraryMovieController.php:366` | `import` | `POST libraries/movies/import/{service}` (`:282`) | 1 | no | yes | as #7 |
| 10 | `Libraries/Controllers/LibraryMusicController.php:466` | `import` | `POST libraries/music/import/{service}` (`:248`) | 1 | no | yes | as #7 |
| 11 | `Libraries/Controllers/LibraryRecipeController.php:284` | `import` | `POST libraries/recipes/import/{service}` (`:276`) | 1 | no | yes | as #7 |
| 12 | `Notes/Controllers/NoteController.php:106` | `journalMarks` | `GET notes/journal/marks` (`:146`) | 2 | no, read | **yes**, = #4 | OK under 3.1. See note (b) |
| 13 | `Notifications/Controllers/NotificationSettingsController.php:107` | `update` | `PUT notifications/settings` (`:376`) | **36** | **yes** | no | **violation** (> 3 fields **and** `update`) |
| 14 | `Shortcuts/Controllers/ShortcutController.php:220` | `reorder` | `PUT shortcuts/reorder` (`:347`) | 1 (`shortcuts`, `shortcuts.*`) | no: reorder action | no | OK |
| 15 | `TimeTracking/Controllers/TimeTrackingEntryController.php:260` | `start` | `POST time-tracking/entries/start` (`:393`) | 2 | no | no | OK. `project_id` is re-checked with `isResourceOwner` (`:267`) |
| 16 | `TimeTracking/Controllers/TimeTrackingEntryController.php:384` | `statistics` | `GET time-tracking/entries/statistics` (`:395`) | 3 | no, read | no | OK. See note (d) |
| 17 | `Todos/Controllers/TodoController.php:320` | `addSubtask` | `POST todos/{todo}/subtasks` (`:131`) | 1 | no | no | OK (the model case in rule 3.1; the rule cites `:312`, now `:320`) |
| 18 | `Todos/Controllers/TodoController.php:383` | `updateSubtask` | `PUT todos/{todo}/subtasks/{subtask}` (`:132`) | 2 (`title`, `is_completed`) | **yes** | no | **owner decision**: reference module vs "never on update", note (a) |
| 19 | `Todos/Controllers/TodoWorkspaceController.php:272` | `attachCategories` | `POST todos/workspaces/{workspace}/categories/attach` (`:125`) | 1 | no | yes, = #20 (same controller) | OK. See note (c) |
| 20 | `Todos/Controllers/TodoWorkspaceController.php:334` | `detachCategories` | `POST …/categories/detach` (`:126`) | 1 | no | yes, = #19 | OK |
| 21 | `Users/Controllers/PasskeyController.php:91` | `update` | `PUT auth/passkeys/{passkey}` (`:91`) | 1 (`name`) | **yes** | no | **owner decision**: same question as #18, note (a) |

All paths are relative to `app/Api/`.

Result: 21 sites. 1 clear violation (#13). 7 owner decisions: 2 `update` actions with ≤ 3 fields (#18, #21) and 5 duplicated `url` shapes (#7-11). 13 OK, including #6, which shares the `url` shape, and the #19/#20 pair.

Notes:

- **(a) `update` with 1-2 fields.** The Decision says "never on `update`", but #18 is in the owner's reference module, and #21 is a one-field rename. Per "Decisions" bullet 1, a Todos/rule conflict goes to the owner. Options:
  1. Keep the rule. The follow-up adds `UpdateTodoSubtaskRequest` and `UpdatePasskeyRequest` (two small files, `sometimes` rules).
  2. Narrow the rule to "never on the `update` of a top-level resource". A child resource or single-attribute rename may stay inline when ≤ 3 fields.

  **Recommendation:** option 1. It keeps the check grep-able ("no `validate(` inside a method named `update*`") and costs two tiny files.
- **(b) `from`/`to` marks, #4 and #12.** Both are GET endpoints, so rule 3.1 ("a write endpoint has a FormRequest") does not strictly apply. But the shape *and* the 366-day `ValidationException` block are copied in two modules. Recommendation: leave it unless a third copy appears. Folding them into one shared request would be a cross-module abstraction (rule 12.8, hard rule on new abstractions).
- **(c) `url` ×5 in the Libraries `import` actions.** The rule's "shape exists nowhere else" clause fails: the same one-line rule is repeated five times in one module (six with `FeedController::testFeed`). Options:
  1. Accept. A one-line, one-field rule is cheaper inline than a class.
  2. Add `Libraries/Requests/ImportFromUrlRequest.php` and use it in the five controllers.

  **Recommendation:** option 1, and reword the clause in the follow-up to "a shape of more than one field that exists nowhere else", so the rule matches what the codebase does. #19/#20 fall under the same reasoning.
- **(d) `statistics` `category_id`** uses bare `exists:time_tracking_categories,id`, which is rule 3.2's pattern. It does not leak data: `TimeTrackingService::getStatistics:107-111` starts from `TimeTrackingEntry::forUser($user->id)` and only filters by the category. It still counts as an unscoped `exists:` in rule 3.2's check, so the follow-up should either scope it or note it as accepted.
- Aside (not rule 3.1): `ClipboardImageService.php:42` and `UserProfileImageService.php:57` re-run `Validator::make` on a file that their FormRequests (`StoreClipboardImageRequest`, `UpdateProfileImageRequest`) already validate. The service versions are stricter: they add `dimensions` (and `mimes` for profile images). Max sizes match. This is out of scope here and is listed for the follow-up only.

## A2: Jobs on default `$tries` / `$timeout` (rule 9.2)

Rule 9.2: set `$tries`/`$timeout` when the default is wrong, as a number, with the reason in the commit.

**The constraint every number must satisfy:** `config/queue.php:54` sets `'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360)`. The comment above it says retry_after must stay above the worker's `--timeout`, or a running job is re-reserved and runs twice. A job-level `$timeout` overrides the worker's `--timeout`, so **every `$timeout` must be < 360** unless the job runs on a separate connection. The existing values (240, 300, 300, 60) all respect this.

**What "default" means here is not visible in this repo.** Without `$tries`/`$timeout`, a job inherits the worker's `--tries`/`--timeout` flags. Those live in the separate deployment repo. Laravel's own defaults are `--tries=1 --timeout=60`, and `composer run dev` uses `queue:listen --tries=1`. The recommendations below are written so they hold whatever the worker flags are.

**Count confirmed:** 18 job files in `app/Api/*/Jobs`. 4 set a value, 14 run on defaults. This matches the backlog.

### All 18 jobs

| Job | `$tries` | `$timeout` | Dispatched / scheduled |
|---|---|---|---|
| `Feeds/Jobs/SyncFeeds` | 1 | 60 | `routes/console.php:8` hourly |
| `Feeds/Jobs/SyncFeed` | 2 | 240 | `SyncFeeds.php:38`, `FeedService.php:114` |
| `Feeds/Jobs/DeleteOldFeedItems` | default | 300 | `console.php:9` daily 02:00 |
| `Export/Jobs/ProcessExport` | default | 300 | `ExportController.php:46` |
| `Libraries/Jobs/GrabMusicReleases` | default | default | `console.php:19` daily 05:00 |
| `Libraries/Jobs/GrabBookReleases` | default | default | `console.php:20` weekly |
| `Libraries/Jobs/GrabMovieReleases` | default | default | **not scheduled** (commented out, `console.php:21-24`) |
| `Libraries/Jobs/ScaleCovers` | default | default | `console.php:25` daily 03:00 |
| `Calendars/Jobs/ImportCalendars` | default | default | `Calendars/Controllers/ImportController.php:118` |
| `Contacts/Jobs/ImportContacts` | default | default | `Contacts/Controllers/ImportController.php:118` |
| `Calendars/Jobs/RefreshCalendarCache` | default | default | `app/Dav/Plugins/CacheInvalidationPlugin.php:112,134` |
| `Contacts/Jobs/RefreshContactsCache` | default | default | `CacheInvalidationPlugin.php:167,189` |
| `Libraries/Jobs/GenerateCoverPreview` | default | default | `LibraryCoverService.php:42,69` |
| `Users/Jobs/ScaleProfileImage` | default | default | `UserProfileImageService.php:39` |
| `Clipboard/Jobs/DeleteOverdueClipboardEntries` | default | default | `console.php:35` daily 02:00 |
| `DevRequests/Jobs/DeleteOldDevRequests` | default | default | `console.php:14` daily 02:00 |
| `Export/Jobs/DeleteExpiredExports` | default | default | `console.php:46` daily 03:00 |
| `Users/Jobs/DeleteOldFriendRequests` | default | default | `console.php:30` daily 02:00 |

### The six named in the brief

| Job | What it does | Retry-safe? | Recommendation |
|---|---|---|---|
| `GrabMusicReleases` | `User::all()` loop (`:33-59`). Per user, `LibraryReleases::getDeezerMusicReleases` (`LibraryReleases.php:39-83`) makes **2 Deezer HTTP calls per distinct favourite artist** (rating ≥ 4), then sends notifications. Runtime grows with users × favourite artists. Laravel's HTTP client default is a 30 s per-request timeout, and no explicit timeout is set. | **No.** The notified set is written per user after that user's notifications (`:56`). A kill mid-user, followed by a retry, re-sends that user's notifications. | `$tries = 1`, `$timeout = 300`. A retry can duplicate notifications, and 300 is the longest value already used that stays under retry_after 360. |
| `GrabBookReleases` | Same loop. **1 Hardcover call per distinct favourite author** (`LibraryReleases.php:137-175`). Weekly. | No, same reason (`:56`). | `$tries = 1`, `$timeout = 300`, same reason. |
| `GrabMovieReleases` | Same loop. Per user, two TMDB requests (upcoming movies, upcoming TV) filtered by the genres of their favourite movies (`LibraryReleases.php:177-188` → `TmdbService::getReleasesForGenres`). Currently not scheduled. | No, same reason (`:57`). | `$tries = 1`, `$timeout = 300` when it is re-enabled, same reason. Setting it now costs nothing. |
| `ScaleCovers` | (1) Walks `storage/app/public/user/*/{music,movies,books,recipes}` and scales every cover without an `_original` sibling to 400 px (`:28-63`). (2) **Then recomputes music releases for every user** (`:67-74`), which is the same Deezer work `GrabMusicReleases` repeats two hours later without notifying. | Part 1 is idempotent (the `_original` marker). Part 2 is idempotent too. | `$tries = 1`, `$timeout = 300`. Daily, and a rerun repeats all Deezer calls for nothing. Follow-up (not 9.2): part 2 duplicates `GrabMusicReleases`, so drop it from `ScaleCovers` or ask why it is there. |
| `ImportCalendars` | Loops `while (stage !== 'finished' && time() - $start < 1800)` (`:32`) over `CalendarImport::importCalendars` / `importEvents` (`app/Dav/Services/CalendarImport.php:75-139`). These fetch from the user's remote CalDAV server and write through `DavService` to the **`pgsql`** DAV database. Each stage runs to completion in one call, so the 1800 s budget is only checked between stages. | **No.** `importEvents` restarts from the first event of every calendar. Duplicate creates are swallowed (`catch (\Exception) { continue; }`), but all the HTTP and write work is redone. | `$tries = 1` (not resumable). **`$timeout`: owner decision**, see below. |
| `ImportContacts` | Same shape (`:32`) over `ContactImport` (`app/Dav/Services/ContactImport.php`), also writing to **`pgsql`**. | No, same reason. | `$tries = 1`. `$timeout`: same owner decision. |

**Owner decision: import timeout vs `retry_after`.** The in-code 1800 s budget in both import jobs cannot hold. Under the current config, the worker kills the job at its `--timeout`, or at the latest when `retry_after` (360 s) re-reserves it and a second copy starts in parallel. Options:

1. `$timeout = 300` and remove the 1800 s condition from the loop. Large imports fail visibly. The progress state stays stuck at the failed stage until its 60-minute cache TTL (`CalendarImport.php:221-224`) expires, so the UI needs to handle that.
2. Keep long imports: run both jobs on a separate queue connection whose `retry_after` is above the job `$timeout` (for example 1800/1700), with its own worker. This changes `config/queue.php` and the worker setup in the deployment repo.

**Recommendation:** option 1 unless there is evidence of real imports taking more than 5 minutes; option 2 is infrastructure. Either way this is flagged because these jobs write to the DAV `pgsql` database (CLAUDE.md hard rule). Nothing in `app/Dav` needs to change for option 1.

### The other eight

| Job | Verdict |
|---|---|
| `RefreshCalendarCache` | Default is fine. Two cache-warming reads (`CalendarService::list`, `listEvents`), idempotent, retry harmless. |
| `RefreshContactsCache` | Default is fine. Same shape. Its `Log::info` (`:28`) is a rule 4.9 backlog item, not 9.2. |
| `GenerateCoverPreview` | Default is fine. One image, idempotent. |
| `ScaleProfileImage` | Default is fine. One image, idempotent. |
| `DeleteOverdueClipboardEntries` | Default is fine. One query plus file deletes, idempotent. Unbatched `->get()`, but clipboard entries live for one day. |
| `DeleteOldDevRequests` | Default is fine. One `DELETE`. |
| `DeleteExpiredExports` | Default is fine. Loop over completed exports older than 48 h, idempotent. |
| `DeleteOldFriendRequests` | Default is fine. One `DELETE`. |

**Out of scope here (9.2 is about numbers):** the three `Grab*Releases` loops will eventually exceed any timeout below 360 s as users grow. The lasting fix is the `SyncFeeds` → `SyncFeed` fan-out (one job per user), which is a design change for a later job.

## A3: `ShareCalendarRequest` `friend_id` vs `Friend` (OQ-7)

OQ-7 asks: "`friend_id` accepts any user id; is sharing meant to be restricted to friends (`Friend` model exists) or is the name just historical?"

**Finding: the premise is wrong. Sharing is already restricted to friends, and has been since the initial commit.** `ShareCalendarRequest` has two parts:

- `rules()` (`app/Api/Calendars/Requests/ShareCalendarRequest.php:14`): `'friend_id' => 'required|uuid|exists:users,id'`. Read on its own, this accepts any user, which is probably what OQ-7 was based on.
- `after()` (`:18-41`): an after-validation hook. It
  - rejects the caller's own id: "You cannot share a calendar with yourself." (`:25-28`)
  - queries `Friend` in both directions (`user_id_1`/`user_id_2`, `:30-34`)
  - adds "You can only share calendars with friends." when there is no row (`:36-38`).

  Laravel runs `after()` hooks as part of FormRequest validation, so a non-friend id gets a **422** before `CalendarController::shareCalendar` runs. `git log -S"You can only share calendars with friends"` → `37f9642 Initial Commit`.

What the controller does (`app/Api/Calendars/Controllers/CalendarController.php:760-783`):

1. `calendarService->get($request->user(), $instanceId)` → 404 if not the caller's.
2. `isShared` → 403 ("You can only share calendars you own").
3. `User::find(friend_id)` → 404 "User does not exist". This branch is effectively unreachable after `exists:users,id`, apart from a race.
4. `calendarService->share()` → `CalendarSharing::inviteUser` (`app/Dav/Services/CalendarSharing.php:33`) → `AppCalendarsPDO::shareCalendarWithUserCustom` (`app/Dav/Backend/AppCalendarsPDO.php:437`). That writes the **`pgsql`** DAV sharing rows through the PDO from `DavService.php:22`. Flagged only; nothing here proposes changing it.
5. Notifies the recipient.

What `Friend` offers (`app/Api/Users/Models/Friend.php`): `user1()`/`user2()` `BelongsTo`, no scope. `User::friends()` (`app/Api/Users/Models/User.php:102-113`) returns a `Collection` of the other users, not a query. So there is no reusable "are A and B friends?" method. The two-direction `where` is written out in `ShareCalendarRequest.php:30-34` and, in list form, in `FeedService.php:193-196`.

**Verdict: owner decision, reduced to a smaller question.** Restricting sharing to friends is settled by the code. What is left:

1. **Close OQ-7** as "restricted to friends; the name is accurate", and correct the "Open questions" entry in the follow-up.
2. **Where the friendship check lives.** Rule 3.3 says ownership checks are controller lines and `authorize()` stays empty. This check is in `after()`, not `authorize()`, and it returns a 422 field error, which is a reasonable answer to "is this a valid recipient". Options:
   - (a) Accept `after()` for cross-record validation and add a sentence to rule 3.3/3.4 so the next audit does not flag it.
   - (b) Move the check to the controller or `CalendarService::share` as a typed exception mapped in `bootstrap/app.php` (rule 7.3 style). That changes the response from 422 to whatever status is mapped (the frontend may rely on the 422 field error).

   **Recommendation:** (a). It is the only `after()` hook (`grep -rn "function after" app/Api/*/Requests` → 1 hit), and it does validation, not action (rule 3.4).
3. **Tests:** no test covers sharing (`grep -rln "/share\|shareCalendar" app/*/*/Tests tests` → none). The follow-up should add two API tests: non-friend → 422, friend → 200. These lock the behaviour this audit found.
4. Minor: the dead 404 branch in step 3 can go when `CalendarController` is next touched. The `User::find` is still needed to get the model.

Not in scope: there is no unfriend endpoint today (`FriendService` has no delete). If one is added, existing shares to a former friend stay in place. Whether unfriending should revoke shares is a product question for that future job.

## A4: Methods over 60 lines outside DAV

Backlog criterion: each is a parser or a protocol step. Split only if a second caller appears.

**How lines were counted:** from the line with the `function` keyword to the method's closing brace, inclusive. Measured with a throwaway PHP tokenizer script (`token_get_all`, brace matching; `use function` imports and closures excluded) over every `*.php` under `app/` except `app/Dav/`. The script was kept outside the repo; to reproduce, any brace-matching counter with the same start/end convention gives the same numbers.

**Result: the list is unchanged.** Exactly six methods are over 60 lines, with the same lengths as the backlog.

| Method | Lines | Where | Callers (production) | What it is | Verdict |
|---|---|---|---|---|---|
| `BggService::getGameDetails` | 75 | `app/Api/Libraries/Services/External/BggService.php:97` | 1: `importFromUrl` (`:30`), private | HTTP call + BGG XML parse into an array | **keep**: parser, single caller |
| `RepairCorruptedVCards::handle` | 73 | `app/Api/Contacts/Commands/RepairCorruptedVCards.php:15` | 0 (artisan `app:contacts:repair-vcards`) | Maintenance command: chunked scan of `cards` with dry-run/apply | **keep**: one-off command. Note below |
| `PasskeyService::register` | 70 | `app/Api/Users/Services/PasskeyService.php:52` | 1: `PasskeyController.php:54` | WebAuthn registration ceremony (challenge, attestation, store) | **keep**: protocol step, single caller |
| `PasskeyService::authenticate` | 68 | `app/Api/Users/Services/PasskeyService.php:139` | 1: `PasskeyController.php:29` | WebAuthn assertion ceremony | **keep**: protocol step, single caller |
| `ImportFeeds::importFromGit` | 65 | `app/Api/Feeds/Commands/ImportFeeds.php:36` | 1: `handle` (`:33`), private | **46 of the 65 lines are a literal list of OPML URLs**; the logic is ~15 lines | **keep**: length is data, not logic |
| `TodoService::parse` | 63 | `app/Api/Todos/Services/TodoService.php:58` | **2**: `TodoController.php:166` (`store`), `QuickAddService.php:144` (`commitTodo`); plus 8 test calls in `TodoApiTest.php:264-320` | Quick-add syntax parser (`#tag`, `/category`, `due:`, `repeat:`, `link:`) | **keep**: see below |

**`TodoService::parse` has a second caller.** Read literally, the backlog's "split only if a second caller appears" is met. But both callers use the *whole* method: same input shape (`['title' => …]`), same output. Splitting helps only when a second caller needs a *part* of it, for example only the `#tag` resolution. **Recommendation:** keep, and in the follow-up reword the criterion to "split when a second caller needs part of it". Separate observation, not a length issue: `parse` creates `Tag` and `TodoCategory` rows (`:72-75`, `:85-88`), so it is not side-effect free, despite its name.

**Next in line (51-57 lines, for awareness, no action):** `HardcoverService::getNewReleases` 57, `FeedService::syncFeed` 56, `ScaleContactPhotos::handle` 55, `ScaleCovers::handle` 54, `RepairCorruptedVCards::repair` 54, `QuickAddService::commit` 51, `UserService::destroy` 51, `NotificationSettingsController::update` 51 (the 36-field inline validation from A1).

**Context, not a length issue:** `RepairCorruptedVCards` (`:19, :29, :50-67`) writes the DAV **`pgsql`** tables (`cards`, `addressbooks`, `addressbookchanges`) from `app/Api/Contacts`. Rule 5.5 already names it, with `ScaleContactPhotos` and `StatisticsService`, as one of the three known `pgsql` users outside `app/Dav`. Its check (`grep -rn "connection('pgsql')" app/Api app/Shared`) finds those three plus a test (`app/Api/Users/Tests/DeleteUserTest.php`). The check line should exclude `Tests`; that goes on the corrections list. Nothing new to flag.

## A5: Route names `->name('x.')` (rule 1.4)

Rule 1.4: "`->name('x.')` is set on seven groups and used by nothing … keep it or drop it, but do not add names to new groups without a caller."

**Drift:** `grep -n -e "->name(" routes/*.php` finds **11 group names + 1 route name** in `routes/api.php`, not seven, plus the Telegram webhook in `routes/web.php`:

| `routes/api.php` | Group | Contains `apiResource('/', …)`? |
|---|---|---|
| `:121` | `todos.` | yes, `:135` |
| `:124` | `workspaces.` (inside `todos.`) | yes, `:127` |
| `:140` | `notes.` | yes, `:148` |
| `:242` | `music.` | yes, `:250` |
| `:253` | `books.` | yes, `:260` |
| `:263` | `links.` | yes, `:267` |
| `:270` | `quotes.` | yes, `:273` |
| `:279` | `movies.` | yes, `:286` |
| `:289` | `games.` | yes, `:294` |
| `:297` | `plants.` | yes, `:298` |
| `:392` | `entries.` | yes, `:397` |
| `:299` | route `->name('uploadCover')` → `plants.uploadCover` | n/a |
| `web.php:8` | `telegram.webhook.solyto` | n/a |

**Callers: none, confirmed.** `grep -rnE "route\(|to_route\(|URL::route|->route\(|routeIs\(|Route::has\(" app tests resources bootstrap config routes database`, which includes tests and views, finds:

- `TelegramBotService.php:40`: `route('telegram.webhook.' …)`. This is the only real caller.
- `UpdateUserRequest.php:13`: `$this->route('user')`, a route *parameter* lookup, not a name.
- `resources/views/welcome.blade.php:24,35,41,43,273`: `Route::has('login')` / `route('login')`. Neither `login` nor `register` is a named route, and no route renders this view, so it is the unused Laravel stub.
- `resources/views/vendor/l5-swagger/index.blade.php:140`: l5-swagger's own route names.

None of these reference the 12 API names. No test uses them either.

**But the group names are not decorative.** All 11 groups wrap an `apiResource('/', …)`, and `apiResource` always generates route names. For resource name `'/'`, Laravel's `ResourceRegistrar` (`getResourcePrefix` → `getResourceRouteName`) strips it to an empty name. So without a group name the routes would be called bare `index`, `store`, `show`, `update`, `destroy`. The group prefix is what makes them `todos.index`, `notes.index`, and so on. Dropping the names would give:

- **11 copies** of `index`/`store`/`show`/`update`/`destroy`
- `genres.*` ×4 (music/books/movies/games)
- `categories.*` ×4: todos (`:122`), notes (`:144`), links (`:264`), and the time-tracking one (`:389`), which is already unprefixed today
- `todos.workspaces.*` collapsing into `todos.*`.

Laravel does not fail at runtime on duplicate names (the name lookup keeps the last one). But `php artisan route:cache` (and `optimize`, which calls it) throws `LogicException: Unable to prepare route [...] for serialization. Another route has already been assigned name [...]` on the first duplicate.

*Verification caveat:* `vendor/` is not installed in this worktree, so this comes from reading the Laravel 12 source (`laravel/framework` v12.58.0 in `composer.lock`) and was not executed. The follow-up should confirm it before acting:

```
php artisan route:list --json | jq -r '.[].name // empty' | sort | uniq -d
```

This should print nothing today. Then run it again with the `->name()` calls removed. Whether production runs `route:cache` is set in the deployment repo, which is not visible here.

**Verdict: owner decision (keep or drop), with a clear recommendation.**

- **Keep uniformly** (recommended). Nothing changes in code. The follow-up corrects rule 1.4: the names have no caller, but they keep the auto-generated `apiResource` names unique, so a new group with `apiResource('/', …)` needs `->name('x.')`. Drop only `->name('uploadCover')` (`:299`): it is the one name not needed for uniqueness and it has no caller.
- **Drop uniformly.** Removing all 12 creates the duplicates above. That breaks `route:cache`, and any future `route()` call against these routes would be ambiguous. To drop safely, each `apiResource` would need `->names([...])` or `->except(...)` replacements. That is more code than it removes, which defeats the point of dropping.

## Backlog corrections

For the follow-up job to apply to `docs/STANDARDS.md` / `docs/STANDARDS_BACKLOG.md`. These files were not edited here.

`STANDARDS_BACKLOG.md`:

1. Audits, item 1: "22 inline sites … all but `TimeTrackingEntryController:158` are ≤ 3 fields" → **21 sites**. The `TimeTrackingEntryController` case is gone. The real exception is `NotificationSettingsController.php:107-144` (36 fields, `update`). Replace the item with the A1 follow-ups.
2. Rewrites, "`TimeTrackingEntryController.php:158-165`: create `UpdateTimeTrackingEntryRequest`": done in `ca1627b`, tick it.
3. Rewrites, "`RefreshCalendarCache` … `ImportContacts.php:18`: `string $userId` …": done in `ca1627b`. All four jobs take `string $userId`, and `grep -rln 'User \$user' app/Api/*/Jobs` → 0. Tick it. (Other "Rewrites" items touched by `ca1627b` may also be unticked; that was not audited here.)
4. Audits, item 2: the count of 14 is correct. Replace "decide" with the A2 numbers and the open import-timeout decision.
5. Audits, item 3: replace with "close OQ-7, add sharing tests" (A3).
6. Audits, item 4: the counts are correct. Change "split only if a second caller appears" to "… needs part of it" (A4).
7. Audits, item 5: "seven groups" → 11 groups + 1 route name (`routes/api.php:121, 124, 140, 242, 253, 263, 270, 279, 289, 297, 392`; `:299`).

`STANDARDS.md`:

8. Rule 1.2 (`:108`) and rule 3.2 (`:318`): "`TimeTrackingEntryController.php:167-170`" → `:161-164` (the `project_id` re-scope in `update`).
9. Rule 1.4: "`->name('x.')` is set on seven groups and used by nothing" → eleven groups. They have no caller, but they keep the `apiResource('/', …)` names unique (see A5). Replace "do not add names to new groups without a caller" with "a group that contains `apiResource('/', …)` needs `->name('x.')`; no other names without a caller".
10. Rule 3.1: "19 of the 22 inline sites" → 21 sites. Swap the `TimeTrackingEntryController.php:158-165` example for `NotificationSettingsController.php:107-144`. `TodoController.php:312` → `:320`. Decide and record the `update` question (A1 note (a)).
11. Rule 9.1: "Four jobs take `public User $user`" is no longer true; they take `string $userId`.
12. Rule 9.2: "The `Grab*Releases` jobs call three external APIs per user" → each calls **one** API (Deezer, Hardcover, TMDB), with one or two calls per distinct favourite. Add the `retry_after` 360 ceiling as the constraint for any `$timeout`.
13. Rule 5.5 check: `grep -rn "connection('pgsql')" app/Api app/Shared` also matches `app/Api/Users/Tests/DeleteUserTest.php`. Add `| grep -v Tests`.
14. Open questions: close OQ-7 (A3) and move the answer to Decisions.
