# Verdict: Standards: rewrites

id: site
reviewer: @reviewer
date: 2026-10-13

## Review

I reviewed against `git diff main...HEAD` on `feature/site_standards-rewrites`. Every changed file is
covered by `implementation.md`. Every changed PHP file passes `php -l`. All the brief/task check greps
give the expected result:
`makeWith` → 0, `report(` after `catch (\Exception` in controllers → 0, `User $user` in Jobs → 0,
`app(` in Services (excluding ExportService) → 0, `catch (\Exception` in External → only `TmdbService.php:235` (Q1).

TASK-1: PASS
notes: `LibraryReleases` and `LibraryRecommender` no longer take a user or type in their constructors.
All seven `makeWith` sites are replaced: constructor injection in the three services, a `handle()`
parameter in the four jobs. `AiService` is injected, and the `hasRecommender()` guard is kept in
`assertType()`, which is called at the top of all four public methods. No singleton binding was added.
In the tests, the mocks are bound through `$this->mock()` before `app(LibraryReleases::class)`, so they
are picked up. Out-of-scope edit: the `(string)` cast in `AiService.php:22`. It is documented and
justified: injection now builds `AiService` on every Book/Music service resolve, and `withApiKey(null)`
would `TypeError` there. It is a one-token change.

TASK-2: PASS
notes: All 10 catches are gone, and the null-result checks after `moveEvent`/`updateEvent` are kept.
The pre-check moved into `CalendarService::create` and throws `CalendarAlreadyExistsException`, which
`bootstrap/app.php` maps to 409 before the `serverError` fallback. The 409 test and the central-500
test were added. No DAV or pgsql change.

TASK-3: PASS
notes: All 6 catches are gone (`destroyContact` keeps its false → 500 check). The pre-check moved into
`ContactService::createAddressBook` and throws `AddressBookAlreadyExistsException` → 409. The 409 test
was added.

TASK-4: PASS
notes: The `RuntimeException` catch was dropped. The other `AuthController`/`PasskeyController` catches
are untouched. A test was added; `AuthService` is imported in `AuthTest.php`.

TASK-5: PASS
notes: The rules were copied verbatim into `UpdateTimeTrackingEntryRequest`, and the ownership checks
stay in the controller. The change in order (422 before 403) is documented in `implementation.md`.

TASK-6: PASS
notes: The four jobs take `string $userId` and call `User::findOrFail` in `handle()`. `User` uses UUID
string keys, so `->id` matches the type. Both dispatch sites were updated. The DAV plugin was not
edited. The latent DAV-dispatch bug and the queue-drain note are recorded in `implementation.md` as
the task asked.

TASK-7: PASS
notes: One `get()` call; the falsy-as-miss behaviour is kept. A new unit test covers hit (callback not
called), miss and falsy. `user_data` exists in `config/cache.php`.

TASK-8: PASS
notes: `Goodreads:47` now catches `ConnectionException`. The four date-parse sites catch
`InvalidFormatException`, following the analyst's correction to the brief. `TmdbService:235` is left
unchanged pending Q1.

TASK-9: PASS
notes: The inner try/catch was removed, so handler errors reach the outer catch (log + default reply).
`routeAction()` is a `match` on `SolytoBotEvent::tryFrom()` with an explicit `false` for unknown
actions, so the entrypoint fallback is kept. `BotInterface` was updated, and no other code reads
`$routes`. The `runCommands` dispatch is left alone and recorded as a follow-up. All three tests were
added.

TASK-10: PASS
notes: Every listed return type was tightened. I checked them against the callees: the `importFromUrl`
DTOs, `create()` return types, `ClipboardService::store`, and `FeedService::createSubscription`. All
return models, so `?Model` on `commit()` holds. The `): mixed` that remain
(`IntegrationGateway::commit`, `DiscogsService::getRelease`, `PasskeyService::cborReadValue`,
`UserCacheService::get`) are outside the brief's named files and are listed as follow-ups.

TASK-11: PASS
notes: `getAncestors`/`getFullPath` moved to `NoteCategoryService` (Q3 default). No remaining callers
of the model methods; `NoteTest` was updated.

## Verification caveat

The Pest suite has not been run, neither by the developer nor by me. `vendor/` is not installed in this
worktree, and the project rules forbid installing packages without asking. This verdict comes from
reading the code, `php -l`, and the check greps.

## Security

Not run.

## Overall

APPROVED

No blockers. The implementation matches `tasks.md`, and the one out-of-scope edit (the `AiService`
cast) is documented and needed because of TASK-1.
