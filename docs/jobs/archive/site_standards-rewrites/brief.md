# Brief: Standards: rewrites

type: feature
id: site
branch: feature/site_standards-rewrites
date: 2026-10-13
author: Leander Muskalla

From `docs/STANDARDS_BACKLOG.md` → "Rewrites (one class or method each)". Rule numbers refer to
`docs/STANDARDS.md`; counts are from 2026-10-13. Items marked **(approval)**
need the owner's go-ahead first.

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
