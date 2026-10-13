# Findings — services (TASK-4)

81 service files, 8 667 lines (`find app/Api/*/Services app/Shared/Services -name '*.php' | xargs wc -l`).
Reference: `app/Api/Todos/Services/TodoService.php` (owner).

## What is built well (credit first)

- **Shape is uniform.** `list/find/create/update/destroy(User|Model, array
  $data)` with constructor-promoted `private readonly` collaborators, a
  `private const string CACHE_KEY`, reads through
  `UserCacheService::remember([$key, $user->id], TTL, fn)`, writes ending
  in `forget`. 24 services follow it verbatim. Typed class constants
  (`private const string`, PHP 8.3) everywhere — the owner writes modern PHP.
- `UserCacheService` (owner) is small, keyed per user, and logs keys but
  never payloads. `IntegrationGateway` (7 methods) is a real seam: the bot
  and QuickAdd never import module services directly.
- `QuickAddService::commit` `:81-130`: one `match` over the content enum,
  one `commitX()` helper per branch that needs more than a `create()` call.
  That is the pattern the Libraries recommend/search/import code should
  have used.
- External services (`Services/External/*`) catch **typed** exceptions
  (`ConnectionException`, `InvalidFormatException`), return `null`/`[]` and
  stay out of the HTTP layer. `FeedService::syncFeed` catches
  `UniqueConstraintViolationException` only (`:320`).
- `TodoService::parse` `:58-120` is the longest owner method (63 lines) and
  is still one thing (turn a quick-add string into attributes); helpers
  `resolveDate`, `spawnNextOccurrence` are private.
- Comments: 27 `//` lines in all non-test `app/Api` code; 5 of them in
  `LibraryReleases:33-37` explain a deferred decision and name the job.
  Default is zero and the repo lives by it.

## 1. Cache invalidation pairs

- `$this->cache->forget([self::CACHE_KEY, $id]); $this->cache->forget([self::CACHE_KEY_DUE, $id]);`
  appears 7× in `TodoService` (`:170-171, 208-209, 255-256, 264-265,
  275-276, 286-287, 297-298`). `TimeTrackingService` has 19 `forget` calls
  for 18 methods, `CalendarService` 19/24, `ContactService` 9/16.
- Where a service has two or three keys, every write repeats the whole
  set. One private `forgetUser(string $userId): void` per service would
  remove the copies; the Todos one is the owner's own and is the example.

## 2. Dispatch by string / service locator

- `call_user_func([$recommender, $type->value])` — `LibraryBookService:118`,
  `LibraryMusicService:120`. `LibraryRecommender` has exactly four public
  methods (`favorite`, `unrated`, `random`, `new`) and
  `LibraryRecommendationEnum` four cases; a `match ($type)` is explicit,
  typed and greppable. `NEW` is already special-cased in `:114-116`, so the
  dynamic call saves two lines.
- `app()->makeWith(LibraryReleases::class, ['user' => $user])` —
  `LibraryBookService:143`, `LibraryMusicService:145`,
  `LibraryMovieService:116`, plus the three `Grab*Releases` jobs `:36` and
  `ScaleCovers:70`. The cause is `LibraryReleases::__construct(…, private
  readonly User $user)` `:22-28`: a service that takes a *user* in its
  constructor cannot be injected and forces every caller through the
  container. Every other service takes `User $user` as a method argument.

## 3. Pass-through and `mixed`

- `findGenre(LibraryXGenre $genre): LibraryXGenre { return $genre; }` ×4
  (`LibraryBookService:170`, `LibraryMovieService:166`,
  `LibraryGameService:126`, `LibraryMusicService:174`). `TodoService::find`
  `:51` at least loads relations; these do nothing.
- Return types that say nothing: `: mixed` on 20 methods / 11 files, 13 of
  them in `Libraries` and `QuickAddService` (`recommendNew`, `import`,
  `search`, `commitX`); `: ?array` on 42 methods / 19 files, almost all in
  `Services/External/*` where the array is a decoded JSON body. The DTOs in
  `Libraries/DTOs/*` exist for the import path (`?BookReleaseDTO`) but not
  for search results.

## 4. Four copies of the Libraries aggregate

`LibraryBookService`, `LibraryMovieService`, `LibraryGameService`,
`LibraryMusicService` (160–208 lines each) share: CRUD with cover save,
`recommend`/`recommendNew`, `releases`, `search`/`import` `match` over a
service enum, and a genre sub-CRUD (`listGenres … destroyGenre`) that is
byte-identical after renaming (`diff` of the genre sections of Book and
Movie: 2 lines). The same holds for the four `Library*GenreController`
files (220–222 lines each, 120-line diff of which 100 are `use`/names) and
their Requests/Resources. This is the one place where the project's "no
new abstractions" rule and DRY collide; the doc records it as an open
question rather than a rule.

## 5. Export services

16 files, 742 lines. `ExportService::export` `:45-61` is a `match` over 15
feature strings, each resolving `app(XExportService::class)->export($user,
$path)`. The 15 feature services are **not** near-identical: 10 write CSV
(same `fopen`/`fputcsv` header + row loop, 28–47 lines), the rest write
ICS/VCF/OPML/HTML/notes. The repetition is the CSV boilerplate, not the
services; a tiny `writeCsv(string $path, array $header, iterable $rows)`
helper would halve those ten. The owner's own 2026-05-14 refactor
("Refactored ExportService to be less of a mess") produced this shape;
it is fine as is.

## 6. Logging

- `Log::` in services: `NoteImportService:59,63,81,89,95` (`Log::info('Processing entry: ' . $path)` — narration of a loop at info level, owner's code) and `UserCacheService` (4 warnings on cache failure, with keys — correct).
- `Log::` in jobs: `RefreshCalendarCache:26` `Log::info('Refreshing calendar cache for user …')`.
- `Log::` in DAV helpers: `ICalendarHelper:54` etc. after swallowing `\Exception` (describe-only).
- Total `Log::` 55 / 19 files; 11 are `Log::info`, the rest `warning`/`error` after a catch.

## 7. Swallowed exceptions

- `catch (\Exception)` without rethrow in services: `TodoService:151`
  (date-format probing — legitimate), `TmdbService:234,265,287`,
  `GoodreadsService:47,113`, `SpotifyService:136`, `InterventionDriver:29,42,72`,
  `FeedReader:109` / `UrlCrawlerService:21` (`\Throwable`).
  The external ones return `null`; the caller cannot tell "no result" from
  "service down". Acceptable for recommendation/search features; worth a
  typed `catch (ConnectionException)` like `DeezerService` uses.
- `IsTelegramBot:165` `catch (\Exception $e) { return false; }` hides every
  bot handler error (the outer handler at `:42` would have logged it).

## 8. Queries

- `whereRaw('LOWER(name) = ?', …)` — `TodoService:73,86` only (owner;
  MariaDB collation is case-insensitive anyway, SQLite in tests is not —
  the raw call exists for the tests).
- `DB::connection('pgsql')` outside `app/Dav`: `StatisticsService:35`,
  `ScaleContactPhotos`, `RepairCorruptedVCards` (commands). Read-only or
  repair tooling; flagged, not proposed for change.
- No `select *`-style N+1 in services: `with([...])` on every list query
  checked; resources that lazy-load are listed in `findings-data.md`.

## 9. Methods doing more than one thing

From `/tmp` method-length scan (methods > 60 lines, non-test, non-DAV):
`BggService::getGameDetails` 75, `RepairCorruptedVCards::handle` 73,
`PasskeyService::register` 70 / `authenticate` 68,
`ImportFeeds::importFromGit` 65, `TodoService::parse` 63. 13 methods over
60 lines in total, 44 over 40. `CalendarController::updateEvent` `:400-453`
(54 lines) is the longest controller action: validate → load → move/update
→ invalidate, with the try/catch around it.
