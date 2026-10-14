# Report: Code quality audit after the standards refactors

date: 2026-10-14
read: false
branch: `main` at `059bbea`
scope: the eight commits from `0ba7665 STANDARDS` (2026-10-13) to `059bbea`
(2026-10-14), judged as a whole: has code quality improved, what is the
state now, what changed in behaviour, what is left, what to do next.
`1bee6fa full user deletion routine` is a feature in the same window and is
only mentioned where it affects a metric.

This is a quality review, not a requirements review: the six job verdicts
under `docs/jobs/archive/*/verdict.md` already check that each brief was
fulfilled. Everything below was measured on this tree, not taken from those
verdicts. Where a verdict and the tree disagree, the tree wins and the
disagreement is reported.

**Overall: ACCEPTABLE.** The refactors did what they set out to do and the
codebase is measurably better than on 2026-10-13 (table below). But `main`
is currently red on both quality gates the standards introduced — 11 test
failures and a failing PHPStan run — and the standards documents are
already out of date. Nothing in the refactored code itself is a blocker;
the blockers are the gates and the process that let them go red.

## How this was measured

- `composer install` from the committed lock (nothing added), then
  `php artisan test`, `./vendor/bin/phpstan analyse`, and
  `./vendor/bin/pint --test --preset laravel` (dry run, nothing written).
- Every "Check:" grep in `docs/STANDARDS.md`, re-run on this tree. Counts
  below marked "today" come from those greps.
- `git diff 0ba7665..HEAD` for behaviour changes, read commit by commit.
- Environment caveats: `.env.testing` is a `/dev/null` device mount in this
  sandbox (shows as `M` in `git status`; not a change), and the 742 test
  "warnings" are the resulting dotenv notices, not failures.

## 1. Did the changes improve things?

Yes, and the improvement is reproducible. "Before" is the count written in
`docs/STANDARDS.md` on 2026-10-13; "today" is the same grep now.

| Rule | What | Before | Today |
| --- | --- | --- | --- |
| 1.5 / 7.1 | `env()` in routes; `response()->json` outside `ApiResponse` | 1 / 1 | 0 / 0 |
| 2.1 | `@OA` paths with `/api` or `/v1` prefix | 268 | 0 |
| 2.2 | documented operations with no route | 80 | 2 (owner decision, pinned in `OpenApiSpecTest`) |
| 2.3 | inline `success`/`message` envelope in controllers | 137 | 0 (a second variant remains, §3.12) |
| 2.4 | validation 422s pointing at `ErrorResponse` | 21 | 2 (both hand-raised, correct) |
| 2.5 | duplicate `operationId`s | 9 | 0 |
| 2.6 / 6.2 | resources without `@OA\Schema`; schema ≠ `toArray()` | 4; 10 | 0; 0 (guarded by `ResourceSchemaDriftTest`) |
| 3.2 | bare `exists:` on user-owned ids | 40 | 7 (§3.15; 5 are re-scoped elsewhere or legitimately cross-user) |
| 4.1 | `app()->makeWith` | 7 | 0 |
| 4.3 | string dispatch (`call_user_func`, `$this->{$x}`) | 3 | 1 (`IsTelegramBot::runCommands`) |
| 4.5 | pass-through `findGenre()` | 4 | 0 |
| 4.6 | `: mixed` in Libraries services | 20 | 0 (5 remain repo-wide, 4 are honest wrappers) |
| 4.9 | `Log::info` narration | 11 | 5 (all in the four Calendar/Contact jobs) |
| 5.1 | untyped relation methods on `User` | 4 | 0 |
| 5.2 | `scopeForUser` signatures | 2 shapes | 1 shape in 37 models |
| 6.1 | resources reading an unloaded relation | 3 | 0 |
| 7.2 | `catch (\Exception) { report(); 500 }` in controllers | 16 | 0 |
| 9.1 | jobs carrying a `User` model | 4 | 0 |
| tests | `it(` cases | 720 | 778 (+58 tenant-isolation and regression cases) |
| 11.1 | `!$x` vs `! $x`; `fn(` vs `fn (` | 137/54; 48/313 | 139/52; 48/333 — **unchanged** |

Net: 234 files, +3 297 / −2 028 lines across `app`, `bootstrap`, `routes`,
`tests`. The two generations of OpenAPI annotations are now one; the
tenant-isolation hole is closed; the service-locator and user-in-constructor
patterns are gone; the controllers no longer re-render exceptions that
`bootstrap/app.php` renders. The one row that did not move is formatting
(§3.4).

### Per-commit quality

- **`d48f7b9` tenant isolation — GOOD.** One uniform shape
  (`[Rule::exists('t', 'id')->where('user_id', $this->user()->id)]`) in 34
  rules, one test per module that posts a foreign id and asserts nothing was
  stored. Nothing to change.
- **`f3545db` mechanical cleanups — GOOD.** Large (75 files) but genuinely
  mechanical, and it was the one job that ran the suite before merging
  (714 green at the time). `RefreshDatabase` bound once in `tests/Pest.php`
  instead of 44 times; `scopeForUser(Builder, string)` everywhere; health
  route through `ApiResponse` and `config('app.version')`.
- **`0a2db37` extract to helpers — GOOD.** `CsvHelper::write()`
  (`app/Shared/Helpers/CsvHelper.php`, 12 lines) removed ~200 lines of
  identical `fopen/fputcsv/fclose` from ten exporters and the exporters now
  read as "query, header, row mapping" (`BookExportService.php:11-32`).
  `withCount('comments')` removed a query per dev request; the `recommend()`
  `match` collapsed two identical controller branches. Static helper is the
  owner's choice and matches `DockerSecretHelper`.
- **`ca1627b` rewrites — ACCEPTABLE.** Right direction everywhere
  (injection, typed exceptions, `match` dispatch, ids-not-models in jobs),
  but this is the commit that broke the suite (§2.1) and the only one whose
  reviewer approved with "the Pest suite has not been run, neither by the
  developer nor by me". The code findings in §3.5–3.8 are from this commit.
- **`5e41fa1` tooling — ACCEPTABLE.** Larastan is in and
  `ResourceSchemaDriftTest` is a real guard (the reviewer verified it
  catches an injected drift). But `phpstan.neon` analyses the Pest test
  files, which is why the baseline is 74 % test noise and went red one
  commit later (§2.2). The job also recorded the 11 failing tests as
  "pre-existing" and left them.
- **`059bbea` larger cleanups — GOOD code, poor commit.** 80 annotation
  paths now match `routes/api.php`; 58 tenant-isolation tests added. But
  the commit is a `chore: commit all` bundling code with job archives, and
  the new test files are what pushed PHPStan over its baseline. The reviewer
  ran only the touched test files.

Three of the six verdicts approved without executing the full suite; two of
those three merged a red tree. That is the process finding of this audit
(§5.1).

## 2. Blockers (fix before the next merge)

### 2.1 `main` has 11 failing tests since `ca1627b`

```
FAILED  App\Api\Libraries\Tests\GrabReleasesTest  × 8   ArgumentCountError
FAILED  App\Api\Users\Tests\JobTest > ScaleCovers  × 3   ArgumentCountError
Tests: 11 failed, 742 warnings (2140 assertions)
```

Cause: `ca1627b` added a `LibraryReleases $libraryReleases` parameter to
`handle()` in `GrabBookReleases.php:25`, `GrabMovieReleases.php:25`,
`GrabMusicReleases.php:25` and `ScaleCovers.php:24`, and the eleven direct
calls in `GrabReleasesTest.php:37,49,61,83,94,121,133,164,176` and
`JobTest.php:127,143` still pass the old argument list. Production is not
affected (the queue worker resolves `handle()` through the container), but
the eight release-job tests have asserted nothing for four merges. Fix is
one line per call site: add `app(LibraryReleases::class)` (or a
`$this->mock(LibraryReleases::class)` where the test fakes HTTP).
blocker: yes

### 2.2 `./vendor/bin/phpstan analyse` fails with 309 errors

All 309 are in test files that `059bbea` added after the baseline was
generated: `function.notFound` ×114 (`makeUser`), `method.notFound` ×95
(`actingAs`), `property.notFound` ×63, plus 31 non-ignorable `ignore.count`
errors because baseline counts for `WeatherTest.php` and others no longer
match. Zero errors in non-test code.

Root cause is in `phpstan.neon`: `paths: [app]` includes `app/Api/*/Tests`,
and PHPStan cannot type Pest files (global helpers, closure-bound `$this`).
1 753 of the 2 380 baselined errors (74 %) come from test files. Every new
test file will re-break the run. Fix: add `app/*/Tests/*` and
`app/Api/*/Tests/*` to `excludePaths`, regenerate the baseline (it drops to
~627 real app-code findings), and add `"analyse": "phpstan analyse
--memory-limit=1G"` to `composer.json` scripts so the command has one
spelling.
blocker: yes

### 2.3 A routed endpoint is an empty stub, hidden from analysis

`app/Api/Notes/Controllers/NotesImportExportController.php:104`
`public function export(): JsonResponse {}` is routed at
`routes/api.php:142` (`GET notes/export`) and returns nothing, so a call
ends in a `TypeError` → 500. `5e41fa1` discovered this (PHPStan's
non-baselinable `return.missing`) and excluded the whole controller from
analysis instead of fixing or unrouting it, so its `import()` method is
now also unanalysed. Same for `TelegramBotConnectionController::confirmToken()`
(not routed; part of the two kept `OPENAPI_KNOWN_DRIFT` entries). Pre-existing
bug, but now known and deliberately masked — decide (route it to a real
implementation, or delete method + route + annotation) and remove both
`excludePaths` lines.
blocker: yes (it is a live 500 on a documented endpoint)

## 3. Remaining issues (non-blocking)

### 3.1 The standards documents went stale within a day

`docs/STANDARDS.md` still says, in present tense: rule 3.2 "Currently 40
hits, 0 scoped" (today 7 / 33 scoped); 4.2 "Today it also hits the three
`Library*Service.php` `makeWith` sites" (0); 2.6 "`TodoResource::toArray`
emits 20 keys, its schema lists 14" (0 drift); 6.2 "the four
`*SearchResultResource` files do not [have a schema]" (they do); 8.3
"`remember` does `has()` → `get()` → `get()`" (one `get()` now); 9.1 "Four
jobs take `public User $user`" (none); 1.4 "seven groups" (eleven); 7.2
"this, 16 times in `CalendarController` (10) and `ContactController` (6)"
(0). `docs/STANDARDS_BACKLOG.md` has 50 open / 4 ticked items, though
roughly 30 of the open ones are done (`0. First` tenant isolation, the
whole "Mechanical" block, most of "Rewrites"). The audit report
`docs/reports/standards-audits-2026-10-13.md` lists 14 concrete
corrections; none is applied.

The document's own Decision ("may carry counts and line numbers as long as
they will not go stale within weeks") was violated by the first refactor
wave. Suggested shape: rule text without counts, a "Check:" grep whose
*target* is stated (→ 0), and the actual numbers only in dated reports like
this one. Tick the backlog at merge, as part of the job's definition of done.
blocker: no, but it is the highest-leverage non-code fix

### 3.2 `LibraryRecommender` — rewritten, but the null path and the copies were kept

`app/Api/Libraries/Services/LibraryRecommender.php:52-63` (`favorite`) and
`:66-78` (`unrated`) dereference `$item->id` when `first()` returns null
(a user with no rating ≥ 4, or no unrated items) → 500. `random()` at
`:86` checks `if (!$item) return null`, so the three siblings in one file
disagree. All three also carry the same five-key array literal; one
`private function toRecommendation(Model $item): array` removes two copies
(rule 4.4). `assertType()` at `:118` throws a bare `\Exception`; the repo
has typed exceptions for this (rule 4.8). Pre-existing on the null path,
but the method was rewritten in `ca1627b` and the chance was missed. The
`LibraryServiceTest` cases cover only the happy paths.
blocker: no

### 3.3 Job `handle()` dependencies are resolved two different ways

`Grab*Releases` and `ScaleCovers` take their collaborators as `handle()`
parameters (Laravel's documented shape). `RefreshCalendarCache.php:23`,
`RefreshContactsCache.php:26`, `ImportCalendars.php:24`, `ImportContacts.php:24`
— the four jobs rewritten in the same commit — call `app(XService::class)`
inside `handle()` instead. `RefreshContactsCache.php:5,7` also imports
`CalendarService` and `DavService` and uses neither. Pick the `handle()`
parameter shape (rule 4.2's spirit) and drop the unused imports.
blocker: no

### 3.4 Formatting did not move, and the new code is in both dialects

Pint dry-run: 221 of 591 files under `app/` would change (159 non-test
files under `app/Api`); top fixers `not_operator_with_successor_space` 88,
`braces_position` 78, `single_line_empty_body` 75,
`class_attributes_separation` 67, `binary_operator_spaces` 54,
`concat_space` 53. The refactors themselves added mixed code:
`LibraryRecommender.php:84` (`$item =  $type`, double space), `:97`
(`fn($q)`), `:118` (`!$type`); `LibraryBookService.php:20-23` (column-aligned
constants); `TimeTrackingService.php:28` (`fn()`). OQ-6 (commit `pint.json`,
run once) is still open; every merge while it is open widens the diff that
run will produce. Rule 11.1 says "match the file you are in" — the new code
did not.
blocker: no

### 3.5 `CalendarController` is still 1 001 lines for ~170 lines of logic

The ten `try/catch` blocks are gone, but eleven actions
(`updateCalendarColor`, `destroyCalendar`, `storeEvent`, `updateEvent`,
`destroyEvent`, `destroyEventOccurrence`, `updateEventOccurrence`,
`listSharees`, `shareCalendar`, `revokeShare`, `unsubscribeFromCalendar`)
open with the same five lines:

```php
$calendar = $this->calendarService->get($request->user(), $instanceId);
if ($calendar === null) {
    return ApiResponse::error('Calendar does not exist', 404);
}
```

Calendars are DAV DTOs, not Eloquent models, so route binding cannot do
this; rule 7.3 ("a controller never queries to decide") suggests the
service throws a typed not-found exception mapped centrally — or, smaller,
a private `calendarOr404()` in the controller. The same preamble pattern is
in `ContactController` (nine actions) with the address book.
blocker: no

### 3.6 Growing `instanceof` chain in `bootstrap/app.php`

`CalendarAlreadyExistsException` and `AddressBookAlreadyExistsException`
are mapped at `bootstrap/app.php:96-102`, after the framework exceptions.
Two is fine. The third module exception should not be a third `if`: Laravel
exceptions can carry their own `render()` (built-in, not a new abstraction),
or the two classes can extend a shared `HttpException` with status 409 and
fall into the existing `HttpException` branch at `:88`. Both exception
classes also keep the Laravel stub's empty `//` body
(`CalendarAlreadyExistsException.php:9`, `AddressBookAlreadyExistsException.php:9`)
— delete the comment line (rule 10.1).
blocker: no

### 3.7 Bot: `exit(1)` inside a web request, and a half-finished dispatch cleanup

`app/Bots/SolytoBot.php:259,265` call `exit(1)` from `auth()` when the chat
is not connected. That ends the PHP process mid-request, skipping Laravel's
terminate phase and the outer `catch` in `IsTelegramBot::handleWebhook`.
Return a value or throw a typed exception the trait catches. Pre-existing,
not touched by `ca1627b`, but `ca1627b` rewrote the dispatch in the same
class. Also from that commit: `routeAction()` is a proper `match` now, but
`IsTelegramBot::runCommands()` at `:159` still does `$this->{$method}()`
(rule 4.3's last hit); `handleWebhook` at `:42` catches `\Exception`, so a
`TypeError` from a handler goes unanswered (`\Throwable`); and
`SolytoBot::commitWithType()` at `:222-231` has had zero callers since
`0fa1f6e` — dead code.
blocker: no

### 3.8 `IntegrationGateway::commit(): mixed`

`app/Shared/Services/IntegrationGateway.php:32` still returns `mixed`
while the method it wraps, `QuickAddService::commit()`, was tightened to
`?Model` in the same commit. The gateway's `?Model` is a one-token change
and `SolytoBot.php:141,208` already check `=== null`.
blocker: no

### 3.9 `UserCacheService::remember()` treats every falsy value as a miss

`UserCacheService.php:33-35` returns `null` for any falsy cached value, so
`remember()` re-runs the callback for an empty collection or `0` on every
call. The behaviour was deliberately kept in `ca1627b` and now has a test
(`tests/Unit/UserCacheServiceTest.php`) pinning it. For a cache whose main
payloads are lists, "empty list is a miss" means users with no todos never
benefit from the cache. Worth an owner decision, not a silent change.
blocker: no

### 3.10 `ScaleCovers` does `GrabMusicReleases`' job a second time

`ScaleCovers.php:67-74` recomputes Deezer releases for every user after
scaling covers; `GrabMusicReleases` does the same two hours later with
notifications. One Deezer sweep per day is the intent; the audit report
(A2) already flagged it. Drop the second half of `ScaleCovers`.
blocker: no

### 3.11 `Log::info` narration — five left, all in the rewritten jobs

`ImportCalendars.php:34,37`, `ImportContacts.php:34,37`,
`RefreshContactsCache.php:28`. Rule 4.9 target is 0 outside commands;
`ca1627b` touched all three files and left them. Also `else if` →
`elseif` in both import loops (Pint `elseif` fixer).
blocker: no

### 3.12 Rule 2.3's check misses the second inline-envelope variant

46 `@OA\Property(property="message", …)` lines remain in controllers:
blocks that declare `data` + `message` without `allOf SuccessResponse`.
Rule 2.3's grep looks only for `property="success"`. Extend the check to
`property="message"` and convert the 46 (mechanical, 12 files — noted by
the `second` job as a follow-up, not yet in the backlog).
blocker: no

### 3.13 `NotificationSettingsController::update` validates 36 fields inline

`:107-144`; a PUT `update` with 36 rules is the clearest rule 3.1 violation
in the repo and the backlog did not list it (audit A1 found it). Add
`UpdateNotificationSettingsRequest`.
blocker: no

### 3.14 `ResourceSchemaDriftTest` is the most complex test in the repo and has no tests

`tests/Unit/ResourceSchemaDriftTest.php` is 190 lines of hand-written
docblock and token walking (depth tracking, string skipping, closure
exclusion). It works today and the `love` reviewer verified it catches an
injected key. But it only sees depth-1 properties and `return [` literals
of `toArray()` itself, silently ignores keys built via `merge()` or
conditionals, and nothing tests the parser. A resource that switches to
`$this->merge([...])` would pass vacuously. Acceptable as a guard; document
the blind spots in `TESTING.md`, and prefer `array_keys($resource->toArray(request()))`
on a factory model when a resource gets complicated.
blocker: no

### 3.15 The seven remaining bare `exists:` rules

- `TimeTrackingEntryController.php:261` (`start`),
  `StoreTimeTrackingEntryRequest.php:17`, `UpdateTimeTrackingEntryRequest.php:17`
  — `project_id`, re-scoped in the controller three times
  (`:92-94`, `:161-163`, `:265-267`) with `TimeTrackingProject::find` +
  `isResourceOwner`. Correct, but a scoped `Rule::exists` in the two
  requests deletes nine controller lines and makes it one shape with the
  rest of the repo.
- `TimeTrackingEntryController.php:387` `statistics.category_id` — safe
  (`getStatistics` starts from `forUser`), still a grep hit; scope it.
- `StoreFriendRequestRequest.php:12` `receiver_id`,
  `ShareCalendarRequest.php:14` `friend_id` — legitimately cross-user
  (the latter enforces friendship in `after()`; audit A3 closes OQ-7).
- `StoreDevRequestRequest.php:19` `created_by_user_id` — OQ-8, still open.
  Until it is answered any user can file a dev request attributed to any
  other user. This is the one with a security edge; it needs the decision
  (default: take it from `$request->user()`).
blocker: no (OQ-8 should be answered this week)

### 3.16 Smaller items

- `LibraryReleases.php:39-83` and `:92-136` are the same 45-line loop for
  Deezer and the dormant Spotify path; the `SWITCH` comment at `:31-35` is
  the right kind of comment, but when Spotify is switched on the two loops
  should become one with the service as a parameter.
- `tests/Pest.php:66-82` `expectApiSuccess`/`expectApiError` — still zero
  callers after 58 new tests. Either use them or delete them; `TESTING.md`
  says "use them".
- Factory/model tests are 278 of 778 cases (36 %); none were added, as
  `TESTING.md` asks, and none removed.
- `GrabBookReleases.php:29-30` and its two siblings carry the same two-line
  "why longterm store" comment; one `UserCacheService::longTerm()` named
  constructor would carry the why once.

## 4. Functional and behavioural changes

Grouped by whether an API consumer can see them. All were intended by their
briefs; this list exists so the frontend and the deployment can be checked
against it.

### API-visible

- **`GET /api/v1/health`** (`f3545db`): body is now the standard envelope;
  `status`, `app`, `version` moved under `data`. Breaking for any monitor
  that reads the old top-level fields. `version` comes from
  `config('app.version')` and will now be correct under `config:cache`.
- **Foreign ids are rejected with 422** (`d48f7b9`): `category_id`, `tags.*`,
  `genres.*`, `parent_id`, `categories.*`, `category_ids.*` belonging to
  another user → 422 with a field error, in 34 rules across Todos, Notes,
  Libraries, TimeTracking. Previously accepted and stored.
- **409 messages gained a period** (`ca1627b`): `'Calendar already exists.'`,
  `'Address Book already exists.'`; the pre-check moved from the controller
  into `CalendarService::create` / `ContactService::createAddressBook` and
  is now a typed exception mapped centrally.
- **Action-specific 500 bodies are gone** (`ca1627b`): "Error creating
  calendar", "Error updating contact", … are now the central
  `'An unexpected error occurred.'`. `AuthController::register` no longer
  returns the `RuntimeException` message in a 500 body. Check the frontend
  for string matches on the old messages.
- **`PUT time-tracking/entries/{entry}`** (`ca1627b`): validation
  (`UpdateTimeTrackingEntryRequest`) now runs before the ownership check, so
  a non-owner sending invalid data gets 422 instead of 403.
- **Non-API `AuthenticationException`** (`f3545db`): 401 JSON instead of a
  redirect to the unregistered `route('login')` (which threw).
- **`FeedSubscriptionResource.url`** and **`DevRequestCommentResource.user`**
  (`0a2db37`): now `whenLoaded`; every current producer loads the relation,
  so the keys are still present. A future producer that forgets will drop
  the key silently rather than query.
- **`DevRequestResource.comment_count`**: same value, from `withCount`
  instead of a loaded collection.
- **OpenAPI document** (`f3545db`, `0a2db37`, `059bbea`): all 268 operation
  paths changed spelling (prefix removed, 80 corrected to the real route),
  nine `operationId`s renamed (`search` → `searchLibraryBooks`, `import` →
  `importLibraryBook`, …), 137 response schemas now reference
  `SuccessResponse`, 21 422s reference `ValidationErrorResponse`, four new
  `*SearchResult` schemas, several `{id}` parameters retyped to
  `string/uuid`, `searchFeeds` documented as GET with a query parameter.
  Any generated client is a different client.

### Internal

- **Four jobs serialise a user id, not a `User`** (`ca1627b`):
  `RefreshCalendarCache`, `ImportCalendars`, `RefreshContactsCache`,
  `ImportContacts`. Two consequences the implementation notes flag and this
  audit confirms: (1) jobs already in Redis with the old payload will fail
  to unserialise — drain the queue before deploying; (2)
  `app/Dav/Plugins/CacheInvalidationPlugin.php:112,134,167,189` always
  dispatched these with a string id, which used to `TypeError` into the
  plugin's `catch (\Throwable)` and be logged — so the DAV-triggered cache
  refreshes never ran before and **run now**, once per DAV write. Expect
  more queue traffic from CalDAV/CardDAV clients.
- **`AiService` is built on every Book/Music service resolve** (`ca1627b`,
  constructor injection of `LibraryRecommender`); the `(string)` cast at
  `AiService.php:22` keeps a missing `AI_API_KEY` from throwing until an AI
  call is actually made.
- **Bot**: handler exceptions in routed actions are now logged and answered
  with the default error reply instead of being swallowed and re-routed
  through `entrypoint()`; `BotInterface` gained `routeAction()` and lost
  `$routes`.
- **`UserCacheService::remember`**: one cache round-trip on a hit instead
  of three; falsy-as-miss unchanged.
- **`LibraryBookService::recommendNew` / `LibraryMusicService::recommendNew`**
  are private; the public `recommend()` handles `NEW` without the earlier
  self-recursion.
- **`NoteCategory::getAncestors()` / `getFullPath()`** moved to
  `NoteCategoryService`; `findGenre()` removed from four services. No
  callers outside the module existed.
- **`scopeForUser(Builder, string $userId)`**: an integer argument now
  `TypeError`s. All user ids are UUID strings, every caller was checked.
- **Tests**: `RefreshDatabase` applies to everything under `app/Api` via
  `tests/Pest.php`; four module test files that previously ran without a
  database now get one. Custom expectations `toBeOne`, `toBeBoolean`,
  `greaterThan`, `lessThan` removed.

## 5. Suggestions for the future

### 5.1 Make the gates real before the next refactor wave

The two quality gates the standards introduced are both red and nobody
noticed for four merges, because running them is optional. Concretely:

1. Fix §2.1 and §2.2 (one small commit each).
2. Add `composer` scripts: `"lint": "pint --test"`, `"analyse": "phpstan
   analyse --memory-limit=1G"`, and document in `docs/AGENTS.md` that a
   job is not done until `composer test` and `composer analyse` exit 0 in
   the worktree. A verdict that says "tests not run" is NEEDS WORK, not
   APPROVED.
3. The backlog's "(approval) `.github/workflows/tests.yml`" item is the
   cheapest permanent fix: PHP 8.4, `composer install`, `php artisan test`,
   `phpstan analyse`, on every push. No Docker, no coverage gate. It needs
   the owner's go-ahead and nothing else.
4. Reviewers should run the full suite, not the touched files — the
   `GrabReleasesTest` failures were in files no later job touched.

### 5.2 Decide OQ-6 and run Pint once, now

159 non-test files under `app/Api` are off-preset, and the refactor jobs
added more mixed code. Every merge that happens before the one-time Pint
run makes that run's diff larger and its `git blame` cost higher. Decision
plus one commit. After that, `pint --test` in the gate makes rule 11.1
self-enforcing and the "match the file you are in" clause can go.

### 5.3 Make `STANDARDS.md` timeless and the backlog honest

Strip counts, "today" and "currently" from rule bodies; keep the file:line
bad/good examples (they are the point) and a "Check:" line with a target.
Numbers go in dated reports. Apply the 14 corrections from
`standards-audits-2026-10-13.md` and tick the ~30 done backlog items in one
docs commit. Define that ticking is the merging person's job.

### 5.4 Next refactor wave, in order

1. §2.3 Notes export stub — decide and unmask.
2. OQ-8 `created_by_user_id` — the last tenant-isolation hole.
3. §3.2 `LibraryRecommender` null path + `toRecommendation()`.
4. §3.7 `SolytoBot::auth()` `exit(1)` → return/throw; `\Throwable` in the
   trait; delete `commitWithType()`.
5. §3.5 the `CalendarController` / `ContactController` not-found preamble
   (typed not-found exception from the service, mapped centrally — and
   while there, §3.6 so the central map does not grow by `if`).
6. §3.12 second envelope variant (46) with the rule 2.3 check extended.
7. §3.13 `UpdateNotificationSettingsRequest`.
8. §3.10, §3.11, §3.3, §3.8 — small, one commit together.

### 5.5 Commit hygiene

`059bbea` and `3d143fe` are `chore: commit all`. The first carries 29
source/test files plus a job archive. Job merges should carry the job's
message (`Standards: larger cleanups`, as the other five do) so `git log
-- app/Api/X` stays readable; `chore: commit all` should hold only
`docs/jobs` and `tokens.json`.

### 5.6 What is right and should stay as it is

The service shape (`private readonly` collaborators, typed cache-key
constants, `forgetUser()`), the central exception map, `UserCacheService`,
the `OPENAPI_KNOWN_DRIFT` / `RESOURCE_SCHEMA_KNOWN_DRIFT` allow-list
pattern, `tests/TestCase.php`, and the "one test per module that posts
another user's id" discipline from `d48f7b9` and `059bbea`. The owner's
decisions to keep four genre CRUD copies, a static `CsvHelper`, and the
`after()` hook in `ShareCalendarRequest` are sound and documented. None of
this needs revisiting.
