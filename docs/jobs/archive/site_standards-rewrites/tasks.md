# Tasks: Standards: rewrites

id: site
analyst: @analyst
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

Branch checked: the worktree HEAD is `refs/heads/feature/site_standards-rewrites`, which matches `brief.md`.
No brief item is marked **(approval)**. Rule numbers refer to `docs/STANDARDS.md`.
Line numbers were re-checked against the tree on 2026-10-13; where they have moved
since the backlog was written, the current ones are given.

**Make one commit per task.** TASK-2 and TASK-3 are one controller each, as the brief asks.
For every task, run `composer test` and the brief's check grep before committing.
Don't run Pint over whole files (rule 11.1): only lines you changed should change.

## Task breakdown

TASK-1: Take `User` out of the constructors of `LibraryReleases` and `LibraryRecommender`, pass it as a method argument, inject both classes (and `AiService` into `LibraryRecommender`) instead of using `makeWith`/`new`/`app()`.
  details:
    - `LibraryReleases`: drop `User $user` from `__construct` (`:22-28`). The methods become
      `getMusicReleases(User $user)`, `getSpotifyMusicReleases(User $user)`,
      `getBookReleases(User $user)` and `getMovieReleases(User $user)`. The private
      `getDeezerMusicReleases` also gets `User $user`.
    - There are seven `app()->makeWith(LibraryReleases::class, ['user' => $user])` sites.
      In the three services, inject `LibraryReleases` through the constructor:
      `LibraryBookService:144`, `LibraryMusicService:146`, `LibraryMovieService:116`.
      In the four jobs, resolve it as a `handle()` parameter, the same way they already get
      `UserCacheService`: `GrabBookReleases:36`, `GrabMovieReleases:36`, `GrabMusicReleases:36`
      and `ScaleCovers:70`.
    - `LibraryRecommender`: the constructor currently takes `LibraryTypeEnum $type, User $user`
      (`:41-49`). Move both into the methods: `favorite(LibraryTypeEnum $type, User $user)`
      and the same for `unrated`, `random` and `new`. Keep the `hasRecommender()` guard
      inside the methods, for example in a private `assertType()` or at the top of each
      method. Then inject `AiService` through the constructor, which replaces
      `app(AiService::class)` at `:116` (rule 4.2).
    - `LibraryBookService:112` and `LibraryMusicService:114` change from
      `new LibraryRecommender(...)` to the injected recommender. `recommendNew()` stops taking
      `LibraryRecommender $recommender` as a parameter.
    - Tests in `LibraryServiceTest.php`:
      - `:92-148`: rewrite the `new LibraryRecommender(...)` cases.
      - `:166-258`: change the `makeWith(LibraryReleases::class, ['user' => …, 'xService' => $mock])`
        calls to `app(LibraryReleases::class)` with the mock bound through `$this->mock()`,
        then call `->getXReleases($user)`.
      - `:47-75`: four `app()->makeWith(AiService::class, ['client' => …])` calls are not among
        the "seven call sites", but the brief's check `grep -rn makeWith app → 0` also covers
        test code. Replace them with `new AiService(mockedOpenAiClient(...))`. This changes
        tests only, not behaviour.
    - `AiService` keeps state between calls (`model`, token counts) from `respond*()` until
      `saveUsageForUser()`. That is safe as long as `LibraryRecommender` is not registered as a
      singleton. Don't add a singleton binding.
  files: app/Api/Libraries/Services/LibraryReleases.php, app/Api/Libraries/Services/LibraryRecommender.php,
         app/Api/Libraries/Services/LibraryBookService.php, app/Api/Libraries/Services/LibraryMusicService.php,
         app/Api/Libraries/Services/LibraryMovieService.php, app/Api/Libraries/Jobs/GrabBookReleases.php,
         app/Api/Libraries/Jobs/GrabMovieReleases.php, app/Api/Libraries/Jobs/GrabMusicReleases.php,
         app/Api/Libraries/Jobs/ScaleCovers.php, app/Api/Libraries/Tests/LibraryServiceTest.php
  check: `grep -rn makeWith app` → 0;
         `grep -rn 'User \$user' app/Api/*/Services --include=*.php | grep __construct` → 0;
         `grep -rn 'app(' app/Api/*/Services | grep -vc ExportService` → 0
  depends: none
  risk: medium — it touches 10 files, including scheduled release jobs that send notifications. The job behaviour has to stay the same, and only unit tests cover `LibraryReleases`.

TASK-2: In `CalendarController`, remove the 10 `catch (\Exception) { report(); return 500 }` blocks and move the "calendar already exists" pre-check into `CalendarService::create` as a typed exception that `bootstrap/app.php` maps to 409.
  details:
    - The catch blocks are currently at `:103, 164, 323, 420, 436, 810, 879, 933, 1005, 1048`.
      Keep the null checks that follow some service calls (for example `:425-427` and
      `:441-443`). They map return values, not exceptions.
    - The pre-check is now at `:97-99`, not `:99-101`. Add
      `app/Api/Calendars/Exceptions/CalendarAlreadyExistsException.php` (the module folder is
      new; the pattern is `app/Api/Feeds/Exceptions/`). `CalendarService::create` throws it
      when `getByName()` is not null. Add an `instanceof` branch in `bootstrap/app.php` that
      returns `ApiResponse::error('Calendar already exists.', 409)`. Put the branch before
      the final `serverError` fallback.
    - The DAV backends are not touched: `getByName` already exists and goes through
      `$this->dav->calendars()`. No pgsql or schema change.
    - Tests: add API tests to `CalendarApiTest` for a duplicate name → 409, and for a
      service exception → 500 with the central message (mock the service to throw).
  files: app/Api/Calendars/Controllers/CalendarController.php, app/Api/Calendars/Services/CalendarService.php,
         app/Api/Calendars/Exceptions/CalendarAlreadyExistsException.php (new), bootstrap/app.php,
         app/Api/Calendars/Tests/CalendarApiTest.php
  check: `grep -n -A2 'catch (\\Exception' app/Api/Calendars/Controllers/CalendarController.php | grep -c 'report('` → 0
  depends: none
  risk: medium — error bodies change from action-specific messages ("Error creating event") to the generic 500 message, and the frontend might match on them. `storeEvent:320-322` also wraps `EventDTO::fromRequest`, which still ends in a 500, just through the central handler.

TASK-3: Do the same as TASK-2 for `ContactController`: remove its 6 catch blocks and move the address-book pre-check (`:91-93`) into `ContactService::createAddressBook` as `AddressBookAlreadyExistsException`, mapped to 409.
  details:
    - The catch blocks are at `:97, 352, 445, 515, 598, 666`.
    - The new exception goes in `app/Api/Contacts/Exceptions/`, with a second `instanceof`
      branch in `bootstrap/app.php`.
    - Tests go in `ContactApiTest`: a duplicate → 409.
  files: app/Api/Contacts/Controllers/ContactController.php, app/Api/Contacts/Services/ContactService.php,
         app/Api/Contacts/Exceptions/AddressBookAlreadyExistsException.php (new), bootstrap/app.php,
         app/Api/Contacts/Tests/ContactApiTest.php
  check: `grep -rn -A2 'catch (\\Exception' app/Api/*/Controllers | grep -c 'report('` → 0 (the full check in the brief, after TASK-2 and TASK-3)
  depends: TASK-2 (both change the same block in `bootstrap/app.php`, so do them in order)
  risk: medium — same as TASK-2: the 500 message bodies change.

TASK-4: In `AuthController::register` (`:61-66`), drop the `catch (\RuntimeException) { report(); return serverError($e->getMessage()) }`.
  details:
    - `AuthService::register` throws `RuntimeException` only for "Failed to create user." and
      "Failed to create verification token." (`AuthService.php:25,40`). Both are real server
      failures with no client-facing status to map to.
    - The recommendation is therefore to drop the catch rather than map it. The central
      handler reports the exception and returns a 500.
    - Leave the other four `AuthController` catches and both `PasskeyController` catches
      alone.
  files: app/Api/Users/Controllers/AuthController.php (optionally a test in app/Api/Users/Tests/AuthTest.php
         that mocks `AuthService::register` to throw and expects 500)
  depends: none
  risk: low — the status stays 500. Only the message changes, and it no longer echoes the service's internal text.

TASK-5: Create `UpdateTimeTrackingEntryRequest` with the six rules that are currently inline at `TimeTrackingEntryController.php:158-165`, and type-hint it in `update()`.
  details:
    - Copy the rules exactly as they are, including the bare
      `'project_id' => 'sometimes|exists:time_tracking_projects,id'`.
    - Keep the ownership check at `:167-170` in the controller. Scoping that `exists:` rule is
      backlog item "0. First" (rule 3.2), not this task.
    - Follow the shape of `StoreTimeTrackingEntryRequest` / `UpdateTimeTrackingProjectRequest`.
  files: app/Api/TimeTracking/Requests/UpdateTimeTrackingEntryRequest.php (new),
         app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php
  depends: none
  risk: low — the rules move without changing, and the existing TimeTracking API tests cover the endpoint.

TASK-6: Change the four jobs from `public User $user` to `public string $userId`, and load the user with `User::findOrFail($this->userId)` in `handle()` (rule 9.1; Decisions say ids are the default).
  details:
    - The jobs are `Calendars/Jobs/RefreshCalendarCache.php:17-19`, `ImportCalendars.php:17-19`,
      `Contacts/Jobs/RefreshContactsCache.php:19-21` and `ImportContacts.php:17-19`.
    - Update the dispatch sites `Calendars/Controllers/ImportController.php:118` and
      `Contacts/Controllers/ImportController.php:118` to pass `$request->user()->id`.
    - **Latent bug, flag it in implementation.md:** `app/Dav/Plugins/CacheInvalidationPlugin.php:112,134,167,189`
      already dispatches `RefreshCalendarCache::dispatch($userId)` and
      `RefreshContactsCache::dispatch($userId)` with a **string** id. With today's `User $user`
      constructor that throws a `TypeError`, which the plugin's `catch (\Throwable)` logs to
      the `dav` channel. So these refresh jobs have never been dispatched from DAV writes.
    - After this task they will be dispatched, once per DAV write. That is a behaviour change:
      more queue traffic from DAV clients, though it is what the code intended. The DAV
      plugin itself needs no edit, and no DAV route or schema is touched.
    - Leave the `Log::info` lines in these jobs alone (separate backlog item, rule 4.9).
  files: app/Api/Calendars/Jobs/RefreshCalendarCache.php, app/Api/Calendars/Jobs/ImportCalendars.php,
         app/Api/Contacts/Jobs/RefreshContactsCache.php, app/Api/Contacts/Jobs/ImportContacts.php,
         app/Api/Calendars/Controllers/ImportController.php, app/Api/Contacts/Controllers/ImportController.php
  check: `grep -rln 'User \$user' app/Api/*/Jobs` → 0
  depends: none
  risk: medium — the changed type turns on the DAV-triggered refresh jobs, which are dead today. Also, jobs already queued in Redis with the old `User` payload fail to unserialize after deploy, so the queue should be drained first.

TASK-7: Rewrite `UserCacheService::remember` (`:56-71`) so it reads the cache once: `$result = $this->get($identifiers); if ($result !== null) return $result;` then compute and store as today.
  details:
    - Keep the existing semantics: `get()` treats falsy values as a miss (`! $item` → null),
      so empty collections and arrays are still recomputed. Don't change that here.
    - Add or extend a unit test that asserts a hit does not call the callback.
  files: app/Shared/Services/UserCacheService.php (+ its test, if one exists; otherwise tests/Unit)
  depends: none
  risk: low — the method is small, but every cached read in the app uses it, so run the full suite.

TASK-8: Narrow the `catch (\Exception)` sites in the external Libraries services to the exception each block can actually raise (rule 4.8).
  details:
    **Note: the brief says "catch `ConnectionException`" for all six sites. Reading the code, that is only right for one of them.**
    - `GoodreadsService.php:47`: the try wraps `Http::get` → `ConnectionException`. ✔
    - `GoodreadsService.php:113`, `TmdbService.php:265,287` and `SpotifyService.php:136`: these
      try blocks wrap `Carbon::parse` / `Carbon::createFromFormat` (date probes) and make no
      HTTP call. The correct type is `Carbon\Exceptions\InvalidFormatException`, as in
      `DiscogsService.php:49` and `SteamService.php:47`. Catching `ConnectionException` there
      would let bad dates escape as 500s.
    - `TmdbService.php:234` wraps only the construction of a `MovieReleaseDTO` from an
      already-fetched array; `get()` already catches `ConnectionException` at `:69`. Nothing
      in that block raises a typed exception. The only failure is a missing key, such as
      `$result['title']`, which raises `ErrorException`. The options are to drop the try
      (a malformed TMDB payload then becomes a 500) or to keep it with a why-comment. See
      open question Q1. Until that is answered, leave `:234` unchanged.
  files: app/Api/Libraries/Services/External/GoodreadsService.php, app/Api/Libraries/Services/External/TmdbService.php,
         app/Api/Libraries/Services/External/SpotifyService.php (+ the existing service tests in app/Api/Libraries/Tests)
  check: `grep -rn 'catch (\\Exception' app/Api/Libraries/Services/External` → only `TmdbService.php:234` (until Q1 is answered)
  depends: none
  risk: low — each change narrows one catch, but any exception that is no longer caught now surfaces as a 500. That is why matching the type to the call matters.

TASK-9: In `IsTelegramBot::routeMessage` (`:153-168`), remove the `try/catch (\Exception) { return false; }` so handler errors reach the outer catch in `handleWebhook` (`:42`, log + default reply), and replace the `$this->{$this->routes[...]}()` string dispatch.
  details:
    - **Behaviour to keep:** today an action name that is not in `$routes` raises an undefined-key
      `ErrorException`, which is swallowed, so `routeMessage()` returns false and the bot falls
      through to `entrypoint()`. After the change, an unknown name has to return false
      explicitly. Only real handler failures should reach the outer catch.
    - There is one bot (`SolytoBot`) with one route (`SolytoBotEvent::QUICK_ADD_AWAITING_TYPE` →
      `handleQuickAddTypeSelection`), and `BotInterface:9` declares `public array $routes { get; }`.
    - The brief allows "`match` or a typed map". The smallest option is to replace the `$routes`
      property with a method on the bot, for example
      `routeAction(string $name): bool` in `SolytoBot`, a `match` over `SolytoBotEvent` with
      `default => false`. The interface then declares that method instead of `$routes`. See
      open question Q2.
    - `runCommands():146` `$this->{$method}()` is a second string dispatch, and the rule 4.3
      grep (`\$this->{\$`) also matches it. It is **not** in the brief, so leave it and record
      it under follow-ups.
    - Tests: extend `app/Bots/Tests/SolytoBotTest.php` with three cases: a handler that throws
      → default error reply; an unknown action → `entrypoint`; and the quick-add type
      selection still routes.
  files: app/Bots/Traits/IsTelegramBot.php, app/Bots/SolytoBot.php, app/Bots/BotInterface.php,
         app/Bots/Tests/SolytoBotTest.php
  depends: none
  risk: medium — this is the live Telegram webhook path. Swallowed handler errors become visible replies, and an unknown-action regression would break normal messages.

TASK-10: Replace the `: mixed` return types in the `Library*Service` files and `QuickAddService` with concrete types (rule 4.6).
  details:
    - **Scope is smaller than the brief says.** All seven external `importFromUrl()` methods
      already return `?XReleaseDTO`, and every external `search*()` already builds
      `XSearchResultDTO`s. The "use the search-result DTOs" part is done. What remains is
      types only:
      - `LibraryMovieService::import` → `?MovieReleaseDTO`
      - `LibraryGameService::import` → `?GameReleaseDTO`
      - `LibraryMusicService::import` → `?MusicReleaseDTO`
      - `LibraryRecipeService::import` → `?RecipeReleaseDTO`
      - `LibraryMusicService::search` → `?array`
      - `LibraryBookService` and `LibraryMusicService` `recommendNew()` (now private) → `?array`
      - Optionally add `@return array<XSearchResultDTO>|null` on the four `search()` methods
        (rule 10.2 allows `array<>` docblocks).
    - For `QuickAddService::commit*` (`:81-237`), each `commitX` returns its module's model or
      `null`: `?LibraryMusic`, `?LibraryBook`, `?LibraryMovie`, `?LibraryGame`, `LibraryRecipe`,
      `Todo`. `commit()` itself returns one of about 11 models. Use a union type or
      `?Model` (`Illuminate\Database\Eloquent\Model`), whichever the developer finds clearer;
      `?Model` is the brief's wording.
    - Rule 4.6 says `mixed` is fine only on generic wrappers. Don't change
      `UserCacheService::remember` or `BaseNotification::withLocale`.
  files: app/Api/Libraries/Services/LibraryBookService.php, LibraryMusicService.php, LibraryMovieService.php,
         LibraryGameService.php, LibraryRecipeService.php, app/Shared/Services/QuickAddService.php
  check: `grep -rn '): mixed' app/Api/*/Services app/Shared/Services` → only the two wrapper methods
  depends: TASK-1 (same Library*Service files; `recommendNew`'s signature changes there)
  risk: low — the types are only tightened to what the code already returns. A wrong type shows up as a `TypeError` in the existing Libraries/QuickAdd tests.

TASK-11: Move `NoteCategory::getAncestors()` (`:98-109`) into `NoteCategoryService` as `getAncestors(NoteCategory $category): Collection` (rule 5.1).
  details:
    - The model's `getFullPath()` (`:121-127`) is the only caller, and only
      `NoteTest.php:179-180` calls `getFullPath()`. It is also a tree walk, so it moves along as
      `NoteCategoryService::getFullPath(NoteCategory $category, string $separator = ' > ')`.
      The brief names only `getAncestors`, so moving `getFullPath` is a small scope extension
      that is unavoidable: the model method could not call the service otherwise. See open
      question Q3.
    - Update `NoteTest.php:179-180` to call the service.
    - Keep `isRoot()` and `hasChildren()` on the model; they are small predicates.
  files: app/Api/Notes/Models/NoteCategory.php, app/Api/Notes/Services/NoteCategoryService.php,
         app/Api/Notes/Tests/NoteTest.php
  depends: none
  risk: low — there is one caller, and it is a test.

## Open questions (none block TASK-1…7, TASK-10; defaults given)

- **Q1 (TASK-8): `TmdbService.php:234`.** Nothing typed can be thrown inside the block. Should it
  drop the try (a malformed TMDB payload becomes a 500) or keep `catch (\Exception)` with a
  why-comment (a defensive parse of a third-party payload)? Default: leave the line
  unchanged and ask the owner.
- **Q2 (TASK-9): dispatch shape.** Replace `BotInterface::$routes` with a `match`-based method
  (which changes the interface), or keep `$routes` as a typed map such as
  `array<string, Closure>`? Default: the `match` method, since it is the smallest code and
  there is one bot and one route.
- **Q3 (TASK-11):** OK to move `getFullPath()` along with `getAncestors()`? Default: yes,
  because the model can't call the service otherwise.

## Follow-ups (out of scope, record in implementation.md, do not do)

- `IsTelegramBot::runCommands:146` `$this->{$method}()`: a second string dispatch that rule 4.3's grep matches.
- `Log::info` narration in `ImportCalendars`, `ImportContacts` and `RefreshContactsCache:27`: backlog "Mechanical", rule 4.9.
- `TimeTrackingEntryController` update rule `project_id exists:` is unscoped: backlog "0. First", rule 3.2.
- When this job lands, tick the matching items in `docs/STANDARDS_BACKLOG.md` → "Rewrites". Also check whether the
  `call_user_func` "Extract to helpers" item is already done: `LibraryBookService::recommend` already uses `match`.
