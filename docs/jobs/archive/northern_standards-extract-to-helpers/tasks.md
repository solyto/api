# Tasks: Standards: extract to helpers

id: northern
analyst: @analyst
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

Branch verified: worktree HEAD is `feature/northern_standards-extract-to-helpers`
(matches `brief.md`).

## Findings that change the brief's scope (read first)

1. **OQ-3 is still open** (`docs/STANDARDS.md:915-917`, "Owner to confirm the
   rule applies to the reference"). The brief item itself says "after OQ-3".
   TASK-1 (`TodoService`) is therefore marked **BLOCKED** until the owner
   either closes OQ-3 or confirms that putting it in this brief counts as
   closing it. TASK-2/TASK-3 (TimeTracking, Notes) are not the reference
   module, so rule 4.4 already covers them.
2. **`CalendarService` and `ContactService` do not have the
   `forget`/`forget` pair that `forgetUser(string $userId)` replaces.**
   The "19" for `CalendarService` counts 9 `forget` and 10 `forgetByPrefix`.
   Most are single lines, and the repeated pair is
   `forget([CALENDARS, $id]); forgetByPrefix([EVENTS, $id, $calendarId])`
   (`:66-67, 193-194, 200-201`), which needs a calendar id too.
   `ContactService` keys contacts by `[CONTACTS, $userId, $addressBookId]`
   and repeats the same single line 6×. A `forgetUser(string $userId)`
   helper does not fit either of them without changing the signature. See TASK-4.
3. **`findGenre()` has no callers.** `grep -rn findGenre app` finds only
   the four definitions. The four `Library*GenreController::show` (`:82-87`)
   already return the bound genre. TASK-8 just deletes dead code.
4. **`DevRequestResource` emits the key `comment_count`** (`:27` schema, `:64`
   toArray), not `comments_count`. `withCount('comments')` creates the
   *attribute* `comments_count`. The JSON key must stay `comment_count`,
   because the frontend contract is a separate repo.
5. **Where `writeCsv()` goes is not settled.** The brief names the helper,
   but not where it lives. The existing code has traits for shared behaviour
   (`app/Api/HandlesApiAuth.php`, `app/Bots/Traits/*`) and no shared
   base/helper class in `Export`. See TASK-5.
6. **Resource drift list:** the brief says "ten resources" but names nine.
   The referenced file is at `docs/jobs/archive/dawn_standards/findings-data.md`
   (not `docs/jobs/dawn_standards/...`). Its §3 only details drift for five of
   them (Todo, NotificationSettings, User, UserSettings, DailyWeatherForecast).
   No drift is recorded for DevRequestComment, LibraryRecipe, RecipeRelease
   and FriendRequest. See TASK-13/TASK-14.
7. `STANDARDS.md` 2.6 says `{todo}` is documented as `type="integer"`. That
   is already fixed (`TodoController.php:181` is `type="string", format="uuid"`)
   and is not part of this job.

## Task breakdown

### Cache-forget helpers (rule 4.4)

TASK-1: **BLOCKED on OQ-3.** In `TodoService`, add
`private function forgetUser(string $userId): void` (forgets `CACHE_KEY` and
`CACHE_KEY_DUE`) and replace the 7 pairs at `:170-171, 208-209, 255-256,
264-265, 275-276, 286-287, 297-298` with one call each.
     files: app/Api/Todos/Services/TodoService.php
     depends: owner closes OQ-3 (`docs/STANDARDS.md` Open questions)
     risk: low — the change is mechanical and the existing Todo API/service tests cover every write path. It is blocked only because the owner has not decided yet.

TASK-2: In `TimeTrackingService`, add `private function forgetUser(string $userId): void`
(forgets `CACHE_KEY_ENTRIES` and `CACHE_KEY_PROJECTS`) and replace the 8 pairs
(`:41-42, 52-53, 63-64, 81-82, 104-105, 210-211, 230-231, 241-242`; note
`:210/230/241` list PROJECTS first, so order doesn't matter). Leave the
three single-line `CACHE_KEY_CATEGORIES` forgets (`:159, 169, 179`) as they are.
     files: app/Api/TimeTracking/Services/TimeTrackingService.php
     depends: none
     risk: low — mechanical. Callers pass `$user->id`, `$entry->user_id` or a local `$userId`, and all are string UUIDs.

TASK-3: In `NoteService`, add `private function forgetUser(string $userId): void`
(`CACHE_KEY` + `CACHE_KEY_NEWEST`) and replace the 3 pairs (`:86-87, 102-103,
113-114`). Leave the single `CACHE_KEY_NEWEST` forget at `:119` as it is.
     files: app/Api/Notes/Services/NoteService.php
     depends: none
     risk: low — mechanical, three call sites.

TASK-4: **UNCLEAR, needs owner confirmation before anyone implements it.**
`CalendarService` and `ContactService` have no pair that a
`forgetUser(string $userId)` helper could replace (see Finding 2). There are
two options: (a) skip both and fix the counts in the backlog, or (b) add a
helper with a different signature, e.g.
`forgetCalendar(string $userId, string $calendarId)` for the 3×
`forget`+`forgetByPrefix` pair in `CalendarService`. Do not implement this
without a decision.
     files: app/Api/Calendars/Services/CalendarService.php, app/Api/Contacts/Services/ContactService.php (only if (b))
     depends: owner decision
     risk: medium — CalendarService invalidates on behalf of share recipients (`:182, 193-194`), so a helper keyed only on the caller's user id could silently skip the recipient's cache.

### Export CSV helper (findings-services §5)

TASK-5: Add `writeCsv(string $path, array $header, iterable $rows): void`
(`fopen`, header `fputcsv`, row loop, `fclose`). Convert `TodoExportService`
to use it first, because that is the one CSV exporter `ExportTest.php:113`
already checks. **Open:** where the helper lives. The recommendation is a
trait under `app/Api/Export/Services/` that mirrors `HandlesApiAuth`,
because a new injected class or base class would be a new abstraction.
The developer should confirm this with the owner or note the choice in
implementation.md.
     files: app/Api/Export/Services/TodoExportService.php, new trait file in app/Api/Export/Services/ (name TBD)
     depends: none (location decision above)
     risk: low — `ExportTest` covers the todos CSV, and the row-building closures move as they are.

TASK-6: Convert the other nine CSV exporters to `writeCsv()`: Book, Music,
Game, Recipe, Quote, CheckIn, TimeTracking, FinanceIncome, FinanceWealth.
Rows are passed as a mapped collection or generator. `FinanceWealthExportService`
has a nested `fields → values` loop, so it needs `flatMap` or a generator.
Do not touch Feed/Note/Calendar/Link/Contact (the non-CSV ones). Remove the
unused `use` lines in `FinanceWealthExportService.php:5-9` only if Pint or
the reviewer asks, because they are unrelated. Add one `ExportTest` case
that asserts the header and a row of `finances_wealth.csv`, since that is
the only non-trivial row shape.
     files: app/Api/Export/Services/{Book,Music,Game,Recipe,Quote,CheckIn,TimeTracking,FinanceIncome,FinanceWealth}ExportService.php, app/Api/Export/Tests/ExportTest.php
     depends: TASK-5
     risk: low — mechanical. The only exporter without test coverage that has non-trivial row logic is FinanceWealth, and the new test covers it.

### Libraries (rules 1.3, 4.3, 4.5)

TASK-7: In `LibraryBookService::recommend` (`:110-119`) and
`LibraryMusicService::recommend` (`:112-121`), replace `call_user_func`
with `match ($type)` over FAVORITE/RANDOM/UNRATED/NEW. `NEW` returns
`$this->recommendNew($user)`. `recommendNew` must then call
`$recommender->new()` directly (it currently calls
`$this->recommend($user, NEW)` at `:126`/`:128`), otherwise this creates
infinite recursion. Collapse `LibraryBookController::recommend`
and `LibraryMusicController::recommend` (`:128-153`) to one service call,
one `if (! $recommendation) 404`, and one success. Add API tests for both
endpoints (one non-NEW type and the 404 case) in `LibraryApiTest.php`,
because none exist today.
     files: app/Api/Libraries/Services/LibraryBookService.php, app/Api/Libraries/Services/LibraryMusicService.php, app/Api/Libraries/Controllers/LibraryBookController.php, app/Api/Libraries/Controllers/LibraryMusicController.php, app/Api/Libraries/Tests/LibraryApiTest.php
     depends: none
     risk: medium — the NEW path pops from a cached stack and calls the AI recommender, the recursion trap above is easy to hit, and no HTTP test covers `recommend` today. Check: `grep -rn call_user_func app` → 0.

TASK-8: Delete the four unused `findGenre()` pass-throughs
(`LibraryBookService:170-173`, `LibraryMovieService:166`,
`LibraryGameService:126`, `LibraryMusicService:174`). The controllers
already return the bound genre, so they need no change.
     files: app/Api/Libraries/Services/LibraryBookService.php, LibraryMovieService.php, LibraryGameService.php, LibraryMusicService.php
     depends: none (touches the same files as TASK-7, so do it after TASK-7 to keep the diffs separate)
     risk: low — there are no callers (`grep -rn findGenre app` shows only the definitions).

### Resources: eager loading (rule 6.1)

TASK-9: `DevRequestResource:64`: replace `$this->comments->count()` with
the `comments_count` attribute, and keep the JSON key `comment_count`
(Finding 4). In `DevRequestService`, `list()` becomes
`with('votes')->withCount('comments')`, and `create`/`update`/`vote` use
`load('votes')->loadCount('comments')`. Add an API assertion on
`data.0.comment_count` in `DevRequestApiTest.php`. Out of scope: the
controller's own `$devRequest->load(['votes', 'comments'])` at
`DevRequestController.php:206` becomes redundant. Note it in
implementation.md as a follow-up and don't change it.
     files: app/Api/DevRequests/Resources/DevRequestResource.php, app/Api/DevRequests/Services/DevRequestService.php, app/Api/DevRequests/Tests/DevRequestApiTest.php
     depends: none
     risk: low — if a path forgets `loadCount`, `comment_count` silently becomes null. All four service paths and the new assertion cover this.

TASK-10: `DevRequestCommentResource:34-37`: wrap `user` in
`whenLoaded('user', …)`. Both producers already eager-load it
(`DevRequestService::listComments` `with('user')`, `createComment`
`load('user')`), so the output doesn't change.
     files: app/Api/DevRequests/Resources/DevRequestCommentResource.php
     depends: none
     risk: low — every current code path already loads the relation.

TASK-11: `FeedSubscriptionResource:34`: make `url` go through
`whenLoaded('feed', …)`, and make sure each of the four producers loads
`feed`. `getUserFeeds` already does (`FeedService.php:147`). The others are
`createSubscription` (`:80`), `updateSubscription` (`:241`), and
`FeedController::showSubscription` (`:123-131`), which calls no service at
all. **Open:** whether `showSubscription` gets a small service read or a
`load('feed')` in the controller. Rule 6.1 says "the service decides", but
no read method exists yet. Add an API assertion on `data.url` for
show/update.
     files: app/Api/Feeds/Resources/FeedSubscriptionResource.php, app/Api/Feeds/Services/FeedService.php, app/Api/Feeds/Controllers/FeedController.php, app/Api/Feeds/Tests/FeedApiTest.php
     depends: none
     risk: medium — any producer that misses the load silently drops `url` from the response. The frontend depends on it and no test checks it today.

### OpenAPI docs (rules 2.6, 6.2)

TASK-12: Add `@OA\Schema` blocks (`BookSearchResult`, `GameSearchResult`,
`MovieSearchResult`, `MusicSearchResult`) to the four `*SearchResultResource`
files. Their properties must match each `toArray()` exactly. Optionally
point the four `search` operations' `data` items at the new schemas, but
only if they currently use an inline or absent item schema. The developer
checks this and records it.
     files: app/Api/Libraries/Resources/{Book,Game,Movie,Music}SearchResultResource.php (optionally the four Library*Controller search docblocks)
     depends: none
     risk: low — annotations only. Check: `grep -rL '@OA\\Schema' app/Api/*/Resources` → empty, and `php artisan l5-swagger:generate` succeeds.

TASK-13: Bring the documented five drifting schemas in line with `toArray()`
(findings-data §3). `TodoResource`: add `link`, `recurrence_frequency`,
`recurrence_interval`, `recurrence_ends_at`, `parent_task_id`,
`auto_generated`. `NotificationSettingsResource`: add the 4 `export_ready_*`
keys. `UserResource`: add `confirmed`. `UserSettingsResource`: add
`temperature_unit`. `DailyWeatherForecastResource`: rewrite the schema to
the nested shape it actually emits. Change annotations only, never `toArray()`.
     files: app/Api/Todos/Resources/TodoResource.php, app/Api/Notifications/Resources/NotificationSettingsResource.php, app/Api/Users/Resources/UserResource.php, app/Api/Users/Resources/UserSettingsResource.php, app/Api/Weather/Resources/DailyWeatherForecastResource.php
     depends: none
     risk: low — docblocks only. DailyWeatherForecast is the only one that needs care, because of the nested structure.

TASK-14: For `DevRequestCommentResource`, `LibraryRecipeResource`,
`RecipeReleaseResource`, and `FriendRequestResource`, compare each
`@OA\Schema` with `toArray()` and fix the schema. No drift is recorded for
these four, so the developer must find it. A likely candidate in
DevRequestComment is `user.id` typed `integer`, while user ids are UUIDs.
**Unclear:** the brief says "ten" but names nine. Do not go looking for a
tenth resource. Report the gap instead.
     files: app/Api/DevRequests/Resources/DevRequestCommentResource.php, app/Api/Libraries/Resources/LibraryRecipeResource.php, app/Api/Libraries/Resources/RecipeReleaseResource.php, app/Api/Users/Resources/FriendRequestResource.php
     depends: TASK-10 (same DevRequestCommentResource file)
     risk: low — docblocks only, but the scope is only partly known until the diff is done.

TASK-15: In `TodoController`, add `link` (string, maxLength 2048, nullable),
`recurrence_frequency` (enum daily/weekly/monthly/yearly, nullable),
`recurrence_interval` (integer, min 1, max 365) and `recurrence_ends_at`
(string, nullable) to both request bodies: store `:131-143` and update
`:187-199`. The values come from `StoreTodoRequest.php:15,24-26` and
`UpdateTodoRequest.php:15,25-27`.
     files: app/Api/Todos/Controllers/TodoController.php
     depends: none
     risk: low — annotations only, with values taken directly from the FormRequests.

## Verification (whole job)

- `composer test` is green. That includes `tests/Unit/OpenApiSpecTest.php`,
  where `OPENAPI_KNOWN_DRIFT` must not grow.
- `php artisan l5-swagger:generate` succeeds after TASK-12 to TASK-15.
- `grep -rn call_user_func app` → 0 (TASK-7).
- `grep -rnE '\$this->(user|feed|comments)->' app/Api/*/Resources` → 0, except
  inside `whenLoaded` closures (TASK-9 to TASK-11).
- `grep -rL '@OA\\Schema' app/Api/*/Resources` → empty (TASK-12).

## Open questions for the owner

- OQ-3: may `TodoService` (the reference module) get `forgetUser()`? (TASK-1)
- Calendar/Contact: skip, or allow a helper with a different signature? (TASK-4)
- `writeCsv()` location: trait in `Export/Services` (recommended) or something else? (TASK-5)
- `showSubscription`: service read method or controller `load('feed')`? (TASK-11)
- Which is the tenth drifting resource the brief mentions? (TASK-14)
