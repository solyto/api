# Tasks: currencies-v2

id: wiseguy
analyst: blundetto (drafted for paulie's review)
date: 2026-11-25

## Decisions

- **`string(3)` column, not `enum`.** `temperature_unit` uses `enum([c,f])`
  because it has two fixed values. Currencies are expected to grow, and a
  `string(3)` column with app-level validation adds one without a migration.
  The database default stays `EUR`.
- **No enum / config class.** The 7 codes are only needed in the validation
  rule; the resource just falls back to `EUR`. A plain `in:` rule earns its
  place; a `Currency` enum or config list would be an abstraction with one
  consumer. Revisit if a second consumer appears.
- **No list endpoint.** The app hardcodes the same 7 ISO codes for its picker
  and takes symbols from `Intl`; the API is authoritative for validation only.
  Matches `week_start` / `temperature_unit`. Agreed with paulie.

## Task breakdown

TASK-1: Persist the currency setting
  Add the `currency` column and make the model/factory support it.
  - New migration `add_currency_to_user_settings`: string(3), NOT NULL,
    default `EUR`, after `week_start`. Do not run it against a real database —
    the human sees it first; tests use in-memory SQLite.
  - Add `currency` to `UserSettings::$fillable`.
  - Add `currency` to `UserSettingsFactory` definition (default `EUR`).
  files:
    - database/migrations/2026_11_25_000001_add_currency_to_user_settings.php
    - app/Api/Users/Models/UserSettings.php
    - app/Api/Users/Factories/UserSettingsFactory.php
  depends: none
  risk: low — additive column with a default; no data backfill needed.

TASK-2: Add the update endpoint
  Mirror the `week_start` endpoint.
  - `UpdateCurrencyRequest`: `currency => required|string|in:EUR,USD,GBP,CHF,CAD,AUD,RUB`.
  - `UserSettingsService::updateCurrency(User $user, string $currency)`.
  - `UserSettingsController::updateCurrency` with a full `@OA\Put` annotation
    for `/users/me/settings/currency`, `ApiResponse::success(null, 'Currency
    updated successfully.')`.
  - Route `Route::put('currency', ...)` in the `me/settings` group.
  files:
    - app/Api/Users/Requests/UpdateCurrencyRequest.php
    - app/Api/Users/Services/UserSettingsService.php
    - app/Api/Users/Controllers/UserSettingsController.php
    - routes/api.php
  depends: TASK-1
  risk: low — copy of an existing one-field settings action.

TASK-3: Expose the setting and cover it with tests
  - Add `currency` to `UserSettingsResource::toArray` with `?? 'EUR'` fallback
    and to the `UserSettings` `@OA\Schema` property (enum of the 7 codes).
  - Tests in `app/Api/Users/Tests/UserApiTest.php`: update succeeds and
    persists; an invalid code is rejected with a `currency` validation error
    and the stored value is unchanged; a user without the setting gets the
    `EUR` default from `/users/me`.
  files:
    - app/Api/Users/Resources/UserSettingsResource.php
    - app/Api/Users/Tests/UserApiTest.php
  depends: TASK-2
  risk: low — resource field plus tests.

## Verification

`composer test` and `composer analyse` must both exit 0.
