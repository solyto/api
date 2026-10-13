# Implementation: Standards: rewrites

id: site
date: 2026-10-13

## Summary

All 11 tasks from `tasks.md` are implemented, one commit per task. The open questions used the analyst's defaults: Q1 leaves `TmdbService.php:235` alone, Q2 uses a `match`-based `routeAction()`, and Q3 moves `getFullPath()` along with `getAncestors()`.

**Tests were not run.** `vendor/` is not installed in this worktree, and the project rules forbid installing packages without asking. Every changed PHP file passes `php -l`, and every check grep from the brief and tasks gives the expected result:

- `grep -rn makeWith app` → 0
- `User $user` in Service constructors → 0
- `app(` in `app/Api/*/Services` (excluding ExportService) → 0
- `report(` after `catch (\Exception` in controllers → 0
- `User $user` in `app/Api/*/Jobs` → 0
- `catch (\Exception` in `Libraries/Services/External` → only `TmdbService.php:235` (Q1)

**Run `composer test` before merging.**

## Changes

TASK-1: `LibraryReleases` no longer takes `User` in its constructor. All `get*Releases()` methods (and the private Deezer one) take `User $user` instead.
- `LibraryRecommender` now gets `AiService` through its constructor. `favorite/unrated/random/new` take `(LibraryTypeEnum $type, User $user)`. The `hasRecommender()` guard is now a private `assertType()`.
- `LibraryBookService`, `LibraryMusicService` and `LibraryMovieService` inject `LibraryRecommender` and/or `LibraryReleases` (property `$libraryReleases`).
- `GrabBookReleases`, `GrabMovieReleases`, `GrabMusicReleases` and `ScaleCovers` get `LibraryReleases` as a `handle()` parameter.
- `LibraryServiceTest` is updated, including `new AiService(mockedOpenAiClient(...))` in place of `makeWith`.
- **Small extra change in `AiService.php`:** `withApiKey((string) config('services.ai.api_key'))`.
  - Why: because the recommender is now injected, `AiService` is built every time the Book or Music service is resolved. With no `AI_API_KEY` secret, `DockerSecretHelper` returns `null`, and `withApiKey(null)` would throw a `TypeError` on every book and music endpoint. Before, only the "new" recommendation could hit this.
  - Effect: with the cast, a missing key now only fails when an AI call is actually made.

TASK-2: Removed the 10 generic `catch (\Exception) { report(); return 500 }` blocks in `CalendarController`.
- New `app/Api/Calendars/Exceptions/CalendarAlreadyExistsException.php`. `CalendarService::create()` throws it when `getByName()` finds a calendar.
- `bootstrap/app.php` maps it to `409 'Calendar already exists.'`.
- New tests in `CalendarApiTest`: duplicate name → 409, and a service exception → central 500.

TASK-3: Did the same for `ContactController` (6 catch blocks).
- New `AddressBookAlreadyExistsException`, thrown in `ContactService::createAddressBook()` and mapped to `409 'Address Book already exists.'`.
- New test in `ContactApiTest`: duplicate → 409.

TASK-4: `AuthController::register` no longer catches `RuntimeException`, so the central handler reports it and returns a 500. New `AuthTest` case: the mocked service throws → 500 with the generic message.

TASK-5: New `UpdateTimeTrackingEntryRequest` with the six inline rules copied unchanged. `TimeTrackingEntryController::update()` type-hints it, and the ownership checks stay in the controller.

TASK-6: The four jobs (`RefreshCalendarCache`, `ImportCalendars`, `RefreshContactsCache`, `ImportContacts`) take `string $userId` and call `User::findOrFail()` in `handle()`. Both `ImportController`s now dispatch `->id`.

TASK-7: `UserCacheService::remember()` now does a single `get()`. The existing behaviour that a falsy value counts as a miss is kept. New `tests/Unit/UserCacheServiceTest.php` covers hit (callback not called), miss and falsy.

TASK-8: Narrowed the catch types:
- `GoodreadsService:47` → `ConnectionException`
- `GoodreadsService:113`, `SpotifyService:136` and `TmdbService:265,287` → `Carbon\Exceptions\InvalidFormatException`. These blocks only parse dates; there is no HTTP call in them.
- `TmdbService:235` is unchanged (Q1).

TASK-9: Removed the `try/catch` in `IsTelegramBot::routeMessage()`. It now calls `$this->routeAction($name)`.
- `SolytoBot::routeAction()` is a `match` on `SolytoBotEvent::tryFrom()`. An unknown action returns `false`, which falls through to `entrypoint()`.
- `BotInterface` declares `routeAction()` instead of `$routes`.
- `SolytoBotTest` has three new cases: the quick-add route still works, a handler that throws gets the default error reply, and an unknown action reaches the entrypoint.

TASK-10: Replaced `: mixed` with concrete return types:
- `import()` → `?Movie/Game/Music/RecipeReleaseDTO`
- `LibraryMusicService::search()` → `?array`
- `recommendNew()` → `?array`
- `QuickAddService::commit()` → `?Model`; the `commitX()` methods return their own model types (`Todo`, `?LibraryMusic`, …)

TASK-11: `getAncestors()` and `getFullPath()` moved from the `NoteCategory` model to `NoteCategoryService`, and `NoteTest` is updated.

## Known issues / follow-ups

- **Tests have not been run** (no `vendor/`). Run `composer test` before merging.
- **TASK-6 latent bug (now fixed by side effect):** `app/Dav/Plugins/CacheInvalidationPlugin.php:112,134,167,189` always dispatched `RefreshCalendarCache`/`RefreshContactsCache` with a string id.
  - Before: the old `User $user` constructor threw a `TypeError`, which the plugin's `catch (\Throwable)` logged, so these refresh jobs never ran from DAV writes.
  - Now: they run once per DAV write, which means more queue traffic from DAV clients. The DAV plugin, routes and schema are untouched.
- **Deploy note (TASK-6):** jobs already queued with the old `User` payload won't unserialize into the new shape. Drain the queue before deploying.
- **Error bodies changed (TASK-2/3/4):** action-specific 500 messages ("Error creating event", …) and the register `RuntimeException` text are now the generic `'An unexpected error occurred.'`. Check whether the frontend matches on any of them.
- **The 409 messages now end in a period** (rule 7.4): `'Calendar already exists.'` and `'Address Book already exists.'`.
- **TASK-5 ordering:** validation now runs before the `isResourceOwner` 403 check. A non-owner who sends invalid data gets 422 instead of 403.
- **TASK-9:** exceptions from routed handlers (e.g. `gateway->commit()` failing) are now logged and answered with the default error reply. Before, they were swallowed and the message went through `entrypoint()` again.
- **Q1 is still open:** `TmdbService.php:235` `catch (\Exception)`. The owner should decide between dropping the try and keeping it with a why-comment.
- `IsTelegramBot::runCommands()` `$this->{$method}()` is a second string dispatch that rule 4.3's grep matches. Not in scope.
- Remaining `: mixed` return types outside this brief: `IntegrationGateway::commit()` (could become `?Model`), `DiscogsService::getRelease()`, `PasskeyService::cborReadValue()` and `UserCacheService::get()`.
- `Log::info` narration in `ImportCalendars`, `ImportContacts` and `RefreshContactsCache` (rule 4.9, backlog "Mechanical").
- The `project_id` `exists:` rule in `UpdateTimeTrackingEntryRequest` is still unscoped (rule 3.2, backlog "0. First").
- When this job lands, tick the matching items in `docs/STANDARDS_BACKLOG.md` → "Rewrites".
- `.env.testing` shows as modified in `git status`, but it is a masked device file in this environment. It was not touched or committed.
