# Implementation: Integrations endpoint

id: tide
developer: @developer
date: 2026-10-14

## Summary

Added a single list of instance integrations (`App\Shared\Enums\IntegrationEnum`) with an `isConfigured()` check per case, and a new authenticated endpoint `GET /api/v1/integrations`. The endpoint returns `{<integration>: bool}` for all seven integrations through `ApiResponse::success`, and never returns config values.

## Changes

TASK-1: `app/Shared/Enums/IntegrationEnum.php` (new). This is a backed enum with the cases `HARDCOVER`, `TMDB`, `DISCOGS`, `BGG`, `SPOTIFY`, `TELEGRAM` and `NTFY`, whose values are the lowercase response keys in the order from the brief. `isConfigured()` `match`es each case to `filled(config(...))` on the keys from the brief's table. The two-key cases (Spotify, Telegram, ntfy) require both keys. `MusicServiceEnum` is untouched.

TASK-2: `app/Api/Integrations/Controllers/IntegrationController.php` (new). It has one `index()` action that builds the map with `collect(IntegrationEnum::cases())->mapWithKeys(...)`, so there is no loop in the controller (rule 1.1), and wraps it with the message "Integrations retrieved successfully.". The `@OA\Get` annotation uses `path="/integrations"` and `operationId="integrationIndex"`, with a `SuccessResponse` allOf plus a `data` object that has seven boolean properties, and a 401 that references `ErrorResponse`.

TASK-3: `routes/api.php`. Added `Route::get('integrations', [IntegrationController::class, 'index']);` inside the `auth:sanctum` v1 group, after `statistics/overview`, plus the `use` import in alphabetical order. The route has no name (rule 1.4).

TASK-4: `app/Api/Integrations/Tests/IntegrationsApiTest.php` (new). It has:
- a dataset with one "configured → true" test per case;
- a dataset with "not configured → false" tests per case. Each two-key case has two variants, one with each key missing, which proves both keys are required;
- a test that `data` has exactly the seven keys in order, that every value is a bool, that the values match the config, and that none of the fake config strings set in the test appear anywhere in the response body;
- a test that the request returns 401 without auth.
Every test sets its config explicitly, so nothing depends on the environment.

TASK-5: Ran `composer test` (exit 0, 3118 assertions, `OpenApiSpecTest` included) and `composer analyse` (exit 0, no errors). Nothing needed fixing. No baseline or `OPENAPI_KNOWN_DRIFT` changes.

## Known issues / follow-ups

- Sandbox environment only, not code issues:
  - `vendor/` was missing, so I ran `composer install` from the lock file. This installed no new packages.
  - The gitignored `storage/framework/*` and `storage/app/public/user` directories were missing, so I created them. Without them `ExportTest` fails with `fopen` errors.
  - Every test in the suite reports a phpdotenv warning (`/workspace/.env` is missing). These are not failures, and the exit code is 0.
  - `.env.testing` shows as modified because the sandbox mounts it as a character device. Git cannot hash it, so it was not committed and is not part of this change.
- Possible follow-up (out of scope per the brief): move `MusicServiceEnum::isConfigured()` for Discogs and Spotify onto `IntegrationEnum`.
