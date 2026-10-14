# Tasks: Integrations endpoint

id: tide
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

Branch check: worktree HEAD is `refs/heads/feature/tide_integrations-endpoint`,
matching `brief.md`.

## Context verified

- Config keys exist as named in the brief:
  `services.hardcover.api_key`, `services.tmdb.access_token`,
  `services.discogs.access_token`, `services.bgg.api_key`,
  `services.spotify.client_id` / `client_secret`, `services.ntfy.url` / `topic`
  (`config/services.php:34-81`); `telegram.bots.solyto.telegram_token` /
  `webhook_token` (`config/telegram.php:5-8`).
- `app/Shared/Enums/` already exists (`AuthPlatformEnum.php`, uses `match($this)`).
- New module tests are picked up automatically: `phpunit.xml` (`app/Api/*/Tests`),
  root `pest.php` (`app/Api/*/Tests`), and `tests/Pest.php` binds `TestCase`
  + `RefreshDatabase` to `../app/Api`. No suite config change needed.
- `OpenApiSpecTest` compares every documented path to the route table, so the
  `@OA` path must be exactly `/integrations` (rule 2.1/2.2) and the route must
  exist in the same commit.

## Task breakdown

TASK-1: Add the backed enum `App\Shared\Enums\IntegrationEnum` with the seven cases (`hardcover`, `tmdb`, `discogs`, `bgg`, `spotify`, `telegram`, `ntfy`) and an `isConfigured(): bool` that `match`es each case to the config check in the brief's table.
  files: app/Shared/Enums/IntegrationEnum.php (new)
  depends: none
  risk: low — new, isolated file; only reads `config()`, nothing calls it yet.
  notes:
  - Use `filled(config(...))` for every check (the brief says "filled", and
    `AdminNotification::via()` uses `filled`). `MusicServiceEnum` uses
    `! empty(...)`; the two differ only for the string `"0"`, so this is not
    a behavioural concern, but do not touch `MusicServiceEnum` (out of scope).
  - Case names follow the repo's enum style (UPPER_CASE names, lowercase
    string values, e.g. `case HARDCOVER = 'hardcover';`).
  - The values are the response keys, so they must be exactly the seven
    lowercase strings from the brief, in that order.

TASK-2: Add `App\Api\Integrations\Controllers\IntegrationController` with one `index(): JsonResponse` action that returns `ApiResponse::success(<case value => isConfigured() for every case>, 'Integrations retrieved successfully.')`, with an `@OA\Get` annotation per section 2.
  files: app/Api/Integrations/Controllers/IntegrationController.php (new)
  depends: TASK-1
  risk: low — read-only action with no input, no model and no user data; the only real risk is accidentally returning a config value, and TASK-4 covers that.
  notes:
  - No FormRequest, no service, no resource (brief: module has Controllers
    and Tests only). Build the map without a `foreach` in the controller
    (rule 1.1 check), e.g. `collect(IntegrationEnum::cases())->mapWithKeys(...)`.
    See "Unclear" below on whether the map belongs on the enum instead.
  - `@OA\Get`: `path="/integrations"` (rule 2.1), unique `operationId`
    (e.g. `integrationIndex`, rule 2.5), `tags={"Integrations"}`,
    `security={{"sanctum": {}}}`, 200 with
    `allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")}` plus a
    `data` object with seven boolean properties (rule 2.3), 401 referencing
    `ErrorResponse`. Keep the blank ` *` lines (rule 11.3). Shape reference:
    `LibraryMusicController::integrations()` (`:477-504`).
  - Message is an English sentence ending in a period (rule 7.4).

TASK-3: Register `Route::get('integrations', [IntegrationController::class, 'index']);` inside the `auth:sanctum` `v1` group in `routes/api.php`, plus its `use` import.
  files: routes/api.php
  depends: TASK-2
  risk: low — additive route (rule 13.2); `integrations` does not collide with any existing prefix (the existing `libraries/music/integrations` sits under its own prefix).
  notes: no `->name()` (rule 1.4: only groups containing `apiResource('/', …)` get names). Keep the `use` list alphabetical like the rest of the file.

TASK-4: Add `app/Api/Integrations/Tests/IntegrationsApiTest.php` with: for each of the seven cases, one test that it reports `true` when its config is set and one that it reports `false` when it is not; one test that `data` has exactly the seven keys and every value is a bool; and one test that the endpoint returns 401 without auth.
  files: app/Api/Integrations/Tests/IntegrationsApiTest.php (new)
  depends: TASK-3
  risk: low — tests only; set config with `config([...])` per test so nothing depends on the container's env or Docker secrets.
  notes:
  - For the two-key cases (`spotify`, `telegram`, `ntfy`) the "not
    configured" test should leave one of the two keys empty, so it proves
    both keys are required (a Pest dataset per case is fine).
  - In each test, explicitly set the key(s) under test; do not assume the
    test environment has them empty (`DockerSecretHelper` falls back to
    `getenv` when `APP_DEBUG=true`).
  - "Only booleans" test: also assert that no fake secret value set in
    the test appears anywhere in the response body. Use obviously fake
    strings, never real-looking tokens.
  - Shape per `docs/TESTING.md`: `describe`/`it`, `makeUser()`,
    `actingAs($user, 'sanctum')`, `/api/v1/integrations`,
    `expectApiSuccess` / `expectApiError($response, 401)`.

TASK-5: Run `composer test` and `composer analyse` in the worktree and fix anything they report in the files above (`OpenApiSpecTest` and the PHPStan level-5 checks on the new files are the likely places).
  files: any of the files above; no baseline changes
  depends: TASK-1, TASK-2, TASK-3, TASK-4
  risk: low — verification step; do not add entries to `phpstan-baseline.neon` or `OPENAPI_KNOWN_DRIFT` to make it pass.

## Unclear / for the developer to keep in mind (not blocking)

- **Where the "case → bool" map lives.** The brief only specifies
  `isConfigured()` on the enum and a module with Controllers + Tests only (no
  service). Rule 1.1 says a controller delegates and does not loop. Building
  the map with one `collect(...)->mapWithKeys(...)` expression in the
  controller satisfies the rule and adds nothing the brief did not ask for.
  Adding a static helper on the enum would be a small extra API the brief
  did not mention, so keep it in the controller unless a reviewer objects.
- **Out of scope, do not touch:** `MusicServiceEnum`, the existing
  `GET libraries/music/integrations` endpoint, any server-side behaviour
  when an integration is missing, `ClientContractTest` (no supported
  frontend reads this new endpoint yet, rule 13.6).
- No migration, no package, no `pgsql`/DAV change, no config file change.
