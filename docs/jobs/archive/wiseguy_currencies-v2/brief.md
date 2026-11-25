# Brief: currencies-v2

type: feature
id: wiseguy
branch: feature/wiseguy_currencies-v2
date: 2026-11-25
author: Leander Muskalla

## What

Add a single, user-wide display currency to user settings, following the
existing `temperature_unit` / `week_start` pattern (migration, model, request,
controller action, service method, resource, OpenAPI annotation, tests).

- `user_settings.currency`: string(3), NOT NULL, default `EUR`.
- `PUT /api/v1/users/me/settings/currency` with `{ "currency": "USD" }`,
  validated against the fixed list `EUR, USD, GBP, CHF, CAD, AUD, RUB`.
- Expose `currency` on `UserSettingsResource` (falling back to `EUR` for rows
  written before the column existed), returned as `data.settings.currency`.
- Add `currency` to the model's `$fillable` and the settings factory.
- Add the `UserSettings` OpenAPI schema property and the controller's
  `@OA\Put` annotation.

## Why

A user asked to switch from € to $. Finances currently stores plain numeric
values and the API has no currency concept, so the app forces EUR everywhere.
Instead of a one-off switch, this adds a proper user-wide display currency
setting plus the common European / North-American currencies, so budgets,
budget values and wealth values can be shown in the user's own currency.

## Out of scope

- A currency-list endpoint. The cross-repo contract is that the app duplicates
  the same 7 ISO codes for its picker and takes symbols from `Intl`.
- All frontend work — the picker, money formatting / locale rendering.
- FX conversion, per-budget or per-wealth-field currency, historical amounts.
- Currencies outside the 7 above (LATAM, JPY, CNY, INR, ...). More can be
  requested later.
- New translated API strings — only the usual English response message.

## Notes

- Precedents to mirror: `temperature_unit`
  (`app/Api/Users/Requests/UpdateWeatherTemperatureUnitRequest.php`,
  `UserSettingsService::updateWeatherTemperatureUnit`) and `week_start`
  (`UpdateWeekStartRequest`, `PUT /users/me/settings/week-start`).
- Column type: `string(3)`, not the `enum([c,f])` style used by
  `temperature_unit`. More currencies are expected later; app-level
  validation means adding one is a validation-list change, not a schema
  change.
- Existing rows get `EUR` from the column default; the resource must also
  null-coalesce to `EUR`.
- Cross-repo contract with the app job `perfetto_currencies-v2`: same 7 ISO
  codes, no list endpoint, app validates its own picker against the list.
  Agreed with paulie.
- Do not run `php artisan migrate` against a real database; the migration must
  be shown to the human first. Tests run on in-memory SQLite.
- Code follows `docs/STANDARDS.md`; tests go in
  `app/Api/Users/Tests/UserApiTest.php`.
