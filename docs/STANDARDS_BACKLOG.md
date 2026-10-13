# Standards backlog

Concrete cleanups found while writing `docs/STANDARDS.md`. Each item names
the rule it serves and is sized for one commit unless marked "larger".
Nothing here is a rule; the rules are in `STANDARDS.md`. Tick items off as
they land, and delete this file when it is empty.

Counts are from 2026-10-13 and reproducible with the check line given.
Items marked **(approval)** add or configure tooling and need the owner's
go-ahead first (package rule in `docs/AGENTS.md`).

## 0. First

- [ ] **Tenant isolation on foreign ids (rule 3.2) — security, do before anything else.**
      Scope every bare `exists:` rule on a user-owned table, or re-scope the
      id in the service. Files: `Todos/Requests/StoreTodoRequest.php:20,22`,
      `UpdateTodoRequest.php:21,23`, `StoreTodoWorkspaceRequest.php:14`,
      `Todos/Controllers/TodoWorkspaceController.php:273,335`,
      `Notes/Requests/StoreNoteRequest.php:14,16`, `UpdateNoteRequest.php:14,16`,
      `StoreNoteCategoryRequest.php:13` (`parent_id`),
      `Libraries/Requests/{Books,Movies,Games,Music}/{Store,Update}*.php`
      (`genres.*`, `tags.*`), `Links/UpdateLibraryLinkRequest.php:18`,
      `Links/*`, `Quotes/*` (`tags.*`),
      `TimeTracking/Requests/{Store,Update}TimeTrackingProjectRequest.php:16`
      (`category_ids.*`). Add one API test per module that posts another
      user's id and expects 422/403. Larger than one commit if done at once;
      one commit per module is fine.
      Check: `grep -rn "exists:" app/Api --include=*.php | grep -v Tests | grep -vc "Rule::exists"` → 0 unscoped (40 today).
- [ ] `DevRequests/Requests/StoreDevRequestRequest.php:19` + `DevRequestService::create:21-27`:
      take `created_by_user_id` from `$request->user()`, drop the rule
      (rule 3.2; pending OQ-8).
- [ ] `tmp/export_0198c253-9ddb-72f0-a823-ee387e5a52f6.zip`: a user-data
      export tracked in git since `072fea9`. Remove from the tree, add
      `tmp/` to `.gitignore`; the owner decides whether history needs
      rewriting (it is public on GitHub).

## Mechanical (grep, replace, done)

- [ ] `routes/api.php:60-67`: health route → `ApiResponse::success`, version
      from `config('app.version')` with `env('API_VERSION')` moved to
      `config/app.php` (rule 1.5). Check: `grep -rn "env(" routes` → 0.
- [ ] `@OA` path prefix: one spelling in 268 operations (rule 2.1, after
      OQ-1); then remove the prefix loop from `tests/Unit/OpenApiSpecTest.php:128-137`.
      Check: `grep -rc 'path="/api/' app/Api/*/Controllers` or `/v1/` → all 0.
- [ ] Inline envelope → `allOf SuccessResponse`: 137 blocks in 20 controller
      files (rule 2.3). Check: `grep -rn '@OA\\Property(property="success"' app/Api/*/Controllers` → 0.
- [ ] 422 schema: 54 `ValidationErrorResponse` refs in 21 files →
      `ErrorResponse`; delete the schema from `app/OpenApi/OpenApiSpec.php:54-61`
      (rule 2.4, after OQ-2). Check: `grep -rn ValidationErrorResponse app` → 0.
- [ ] Duplicate `operationId`s `search` ×4 and `import` ×5 in
      `Libraries/Controllers/Library{Book,Movie,Game,Music,Recipe}Controller.php`
      → `searchLibraryBooks`, `importLibraryBook`, … (rule 2.5).
      Check: `grep -rhoE 'operationId="[^"]+"' app/Api | sort | uniq -d` → empty.
- [ ] `TodoController.php:93,181,240,…`: `{todo}`/`{subtask}` path params
      `type="integer"` → `type="string", format="uuid"` where the model has
      `HasUuids` (rule 5.4). Also `{note}`, `{entry}`, `{project}` — check
      each controller against its model.
- [ ] `composer.json`: `"name"`, `"description"`, `"license": "AGPL-3.0-only"`
      (tooling report). One commit.
- [ ] `composer.json`: `"pestphp/pest": "^4.4"`, `"pestphp/pest-plugin-laravel": "^4.0"`
      (tooling report). Lock file unchanged.
- [ ] `tests/Pest.php:18-20` (`toBeOne`), `:66-69` (`function something()`):
      delete. `greaterThan`/`lessThan`/`toBeBoolean` (23 call sites in 6 factory
      test files) → `toBeGreaterThan`/`toBeLessThan`/`toBeBool`, then delete
      the extensions; keep `toBeEmail`. `expectApiSuccess`/`expectApiError`:
      0 callers — delete or start using them (`TESTING.md` says use).
- [ ] `tests/Unit/Pest.php`, `tests/Feature/Pest.php`: delete (both
      re-bind what `tests/Pest.php:6` already binds); 43 module test files
      drop their own `uses(RefreshDatabase::class)` (`app/Api/Pest.php` does it).
      Check: `grep -rl 'uses(RefreshDatabase::class)' app/Api/*/Tests | wc -l` → 0.
- [ ] `docs/CLAUDE.md`: unfilled template — delete (the project context is
      `docs/AGENTS.md`) or fill. Root `AGENTS.md` (0 bytes) and root
      `implementation.md` (ad-hoc leftover, 2026-08-27): delete.
- [ ] `bootstrap/cache/packages.php`, `services.php`: generated files
      tracked since `b10b48c`; remove and add `bootstrap/cache/*.php` to
      `.gitignore` (Laravel's default ignore).
- [ ] `bootstrap/app.php:92-98`: the non-API `AuthenticationException`
      branch redirects to `route('login')`, which is not registered. Return
      401 or drop the branch (rule 1.4).
- [ ] `app/Api/Users/Models/User.php:101-126`: return types on
      `friends()`, `sentFriendRequests()`, `receivedFriendRequests()`,
      `allFriendRequests()` (rule 5.1).
- [ ] `scopeForUser($query, $userId)` → `Builder $query, string $userId` in
      35 models (rule 5.2, after OQ-5). Check: `grep -rhoE 'function scopeForUser\([^)]*\)' app | sort | uniq -c`.
- [ ] `TodoResource.php:45-50`: fold the three `whenLoaded` closing parens
      onto their lines (rule 6.3).
- [ ] `ApiResponse.php:69` brace, `:104-105` double blank;
      `ImportFeeds.php:102` brace; `bootstrap/app.php:3-15` import order
      (rule 11.1) — or let Pint do it (Tooling).
- [ ] `Log::info` narration: `NoteImportService.php:59,63,81,89,95`,
      `RefreshCalendarCache.php:26` (rule 4.9). Check: `grep -rn 'Log::info' app/Api/*/Services app/Api/*/Jobs` → 0.

## Extract to helpers (small, each removes several copies)

- [ ] `TodoService`: `private function forgetUser(string $userId): void`
      for the 7× `forget`/`forget` pair (`:170-171, 208-209, 255-256,
      264-265, 275-276, 286-287, 297-298`); same in `TimeTrackingService`
      (19 calls), `CalendarService` (19), `ContactService` (9),
      `NoteService` (7) (rule 4.4, after OQ-3).
- [ ] `Export/Services/*`: `writeCsv(string $path, array $header, iterable $rows)`
      shared by the 10 CSV exporters (28–47 lines each); keep the five
      non-CSV ones as they are (findings-services §5).
- [ ] `LibraryBookService::recommend:110-119` and `LibraryMusicService:112-121`:
      `match ($type)` over the four `LibraryRecommendationEnum` cases, `NEW`
      handled inside; `LibraryBookController::recommend:131-156` and
      `LibraryMusicController:131-156` collapse to one call (rules 1.3, 4.3).
      Check: `grep -rn call_user_func app` → 0.
- [ ] `findGenre()` pass-throughs in `LibraryBookService:170`,
      `LibraryMovieService:166`, `LibraryGameService:126`,
      `LibraryMusicService:174`: delete, controllers return the bound genre
      (rule 4.5).
- [ ] `DevRequestResource.php:64` `comments->count()` → `withCount('comments')`
      in `DevRequestService` + `comments_count`; `DevRequestCommentResource.php:36`,
      `FeedSubscriptionResource.php:34` → `whenLoaded` + eager load (rule 6.1).
- [ ] Four `*SearchResultResource` files without `@OA\Schema` (rule 6.2);
      ten resources whose schema drifts from `toArray()` (`TodoResource`,
      `NotificationSettingsResource`, `UserResource`, `UserSettingsResource`,
      `DailyWeatherForecastResource`, `DevRequestCommentResource`,
      `LibraryRecipeResource`, `RecipeReleaseResource`, `FriendRequestResource`
      — list in `docs/jobs/dawn_standards/findings-data.md` §3) (rule 2.6).
- [ ] `TodoController.php:131-143,187-199`: add `link`, `recurrence_frequency`,
      `recurrence_interval`, `recurrence_ends_at` to both request-body
      annotations (rule 2.6).

## Rewrites (one class or method each)

- [ ] `LibraryReleases.php:22-28`: drop `User $user` from the constructor,
      pass it to `getMusicReleases(User $user)` etc.; replace the seven
      `app()->makeWith(...)` call sites with injection (rule 4.1).
      Check: `grep -rn makeWith app` → 0.
- [ ] `CalendarController.php` (10 `try/catch`), `ContactController.php` (6):
      remove the `catch (\Exception) { report(); return 500 }` blocks (the
      `AuthController`/`PasskeyController` catches are typed and stay,
      rule 7.2); move the "already exists" pre-checks (`CalendarController:99-101`,
      `ContactController:93-95`) into the services as typed exceptions
      mapped in `bootstrap/app.php` (rules 7.2, 7.3). One controller per
      commit. Check: `grep -rn -A2 'catch (\\Exception' app/Api/*/Controllers | grep -c 'report('` → 0.
- [ ] `AuthController.php:63-66`: `catch (\RuntimeException) { report(); return
      serverError($e->getMessage()) }` is a 500 with a custom message, not a
      status mapping — drop the catch or map the exception to a real status
      (rule 7.2). The other four `AuthController` catches and both
      `PasskeyController` ones are typed → 401/400/422 and stay.
- [ ] `LibraryRecommender.php:116`: `app(AiService::class)` → constructor
      injection (rule 4.2). Its constructor takes `$type`/`$user` (`:41-43`,
      same shape as `LibraryReleases`, rule 4.1), so do it with that rewrite.
      Check: `grep -rn 'app(' app/Api/*/Services | grep -vc ExportService` → 0.
- [ ] `TimeTrackingEntryController.php:158-165`: create
      `UpdateTimeTrackingEntryRequest` (the `Store` one exists, the update
      rules are inline) (rule 3.1).
- [ ] `Calendars/Jobs/RefreshCalendarCache.php:18-20`, `ImportCalendars.php:17-19`,
      `Contacts/Jobs/RefreshContactsCache.php:20`, `ImportContacts.php:18`:
      `string $userId` + `User::findOrFail` in `handle()`, or `SerializesModels`
      (rule 9.1). Check: `grep -rln 'User \$user' app/Api/*/Jobs` → 0.
- [ ] `UserCacheService::remember:56-71`: one `get()` instead of
      `has()`+`get()`+`get()` (rule 8.3).
- [ ] `Libraries/Services/External/TmdbService.php:234,265,287`,
      `GoodreadsService.php:47,113`, `SpotifyService.php:136`: catch
      `ConnectionException` (as `DeezerService` does), not `\Exception`
      (rule 4.8).
- [ ] `app/Bots/Traits/IsTelegramBot.php:161-167`: let the handler
      exception reach the outer catch at `:42` (log + default reply); the
      `routes[]` string dispatch → `match` or a typed map (rules 4.3, 4.8).
- [ ] Return types: `recommendNew(): mixed`, `import(): mixed`,
      `search(): mixed|?array` in the four `Library*Service` files and
      `QuickAddService::commit*` → DTOs / `?Model` (rule 4.6, larger: needs
      the search-result DTOs to be used, not only defined).
- [ ] `NoteCategory::getAncestors()` (`:97`) → `NoteCategoryService` (rule 5.1).

## Larger (a feature's worth)

- [ ] `OPENAPI_KNOWN_DRIFT` (80 entries, `tests/Unit/OpenApiSpecTest.php:16-97`):
      fix the annotation paths module by module (`check-in`, `clipboard`,
      `time-tracking/*`, `address-books/*`, `feeds`, `finances/*`,
      `telegram`, `user-settings` → `users/me/settings`, …) until the
      constant is empty, then delete it (rule 2.2).
- [ ] Libraries genre CRUD ×4 (services, controllers, requests, resources):
      one parameterised service/controller pair or leave as is — OQ-4
      first (rule 4.7).
- [ ] Tenant-isolation tests for the 11 API test files that have none
      (`CalendarApiTest`, `CheckInApiTest`, `ContactApiTest`,
      `DevRequestApiTest`, `FeedApiTest`, `LibraryApiTest`,
      `LibraryRestApiTest`, `TimeTrackingApiTest`, `NotificationApiTest`,
      `WeatherTest`, `ExportTest`) — one "other user gets 403 / empty list"
      per resource (`TESTING.md`).
- [ ] Factory/model tests (278 `it(` in 12 files, 39 % of the suite): stop
      adding them; when a file is touched, keep the relation/scope cases and
      drop "creates a valid X" / "has correct fillable attributes"
      (`TESTING.md`). Not a cleanup commit on its own.

## Audits (read, then decide per hit)

- [ ] 22 inline `$request->validate()` sites against rule 3.1 — all but
      `TimeTrackingEntryController:158` are ≤ 3 fields; confirm none is an
      `update`.
- [ ] 14 jobs on default `$tries`/`$timeout`; decide for `Grab*Releases`,
      `ImportCalendars`, `ImportContacts`, `ScaleCovers` (rule 9.2).
- [ ] `ShareCalendarRequest` `friend_id` vs the `Friend` model — OQ-7.
- [ ] Methods over 60 lines outside DAV (`BggService::getGameDetails` 75,
      `RepairCorruptedVCards::handle` 73, `PasskeyService::register` 70 /
      `authenticate` 68, `ImportFeeds::importFromGit` 65, `TodoService::parse` 63):
      each is a parser or a protocol step; split only if a second caller
      appears.
- [ ] `routes/api.php` `->name('x.')` on seven groups with no caller: keep
      or drop uniformly (rule 1.4).

## Tooling (approval needed for each)

- [ ] **(approval)** `pint.json` with `"preset": "laravel"` + `composer lint`
      script, then one repo-wide `./vendor/bin/pint` commit (OQ-6, rule 11.1).
      No new package.
- [ ] **(approval)** `composer require --dev larastan/larastan`, level 5,
      `phpstan.neon` with `paths: [app]` and `app/Dav` excluded at first
      (tooling report §3). New package.
- [ ] **(approval)** `.github/workflows/tests.yml`: PHP 8.4, `composer install`,
      `php artisan test` on push/PR; no Docker, no coverage gate to start
      (the `4f7deeb`/`703d82a` history). Infrastructure, no package.
- [ ] **(approval)** `.github/dependabot.yml`: add `package-ecosystem: "npm"`.
- [ ] **(approval, later)** coverage driver (`pcov`) in the CI image only,
      `--min` once a baseline exists.
- [ ] A small script (`scripts/` or a Pest test) that compares each
      resource's `@OA\Schema` properties with its `toArray()` keys, so rule
      2.6 has a check. The throwaway version used for this job is described
      in `docs/jobs/dawn_standards/findings-data.md` §3.
