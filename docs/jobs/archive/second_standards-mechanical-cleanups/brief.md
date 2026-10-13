# Brief: Standards: mechanical cleanups

type: feature
id: second
branch: feature/second_standards-mechanical-cleanups
date: 2026-10-13
author: Leander Muskalla

From `docs/STANDARDS_BACKLOG.md` → "Mechanical (grep, replace, done)". Rule numbers refer to
`docs/STANDARDS.md`; counts are from 2026-10-13. Items marked **(approval)**
need the owner's go-ahead first.

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

## Owner decisions (2026-10-13, round 2)

- **Dependency install approved:** `composer install` (from the existing
  `composer.lock`, no new packages) so `composer test` and
  `php artisan l5-swagger:generate` can run in the worktree.
- **TASK-8:** option (a) — bind `RefreshDatabase` for `../app/Api` in
  `tests/Pest.php`, delete the dead `app/Api/Pest.php`, drop the 44 per-file
  `uses(RefreshDatabase::class)`.
- **TASK-10:** fill in `composer.json` `"name"`/`"description"`
  (`solyto/api`, description from the project context).
- **TASK-9 / `docs/CLAUDE.md`:** descoped. The file is mounted into the
  container dynamically; ignore it completely.
- **OQ-2 / TASK-5:** reversed — **keep `ValidationErrorResponse`**. 422
  responses should reference it; check 422s that use `ErrorResponse` (e.g.
  the Todos module) and switch them.
- **OQ-5 / TASK-15:** approved — retype `scopeForUser` to
  `Builder $query, string $userId` in all models.
- **OQ-1 / TASK-4:** confirmed — `@OA\Server` stays `/api/v1`, operation
  paths drop the `/api` / `/v1` prefix (`path="/todos"`).
