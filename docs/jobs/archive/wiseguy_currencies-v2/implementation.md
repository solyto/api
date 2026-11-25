## Summary

Adds a single, user-wide display currency to user settings: a `currency`
column on `user_settings` (default `EUR`), a `PUT /users/me/settings/currency`
endpoint validated against `EUR, USD, GBP, CHF, CAD, AUD, RUB`, and `currency`
in the settings resource/OpenAPI schema. No FX conversion, no per-item
currency, no list endpoint. Mirrors the existing `week_start` /
`temperature_unit` settings.

## Changes

TASK-1: Persist the currency setting
- New migration `2026_11_25_000001_add_currency_to_user_settings.php`:
  `string('currency', 3)->default('EUR')->after('week_start')`.
- `UserSettings`: added `currency` to `$fillable`.
- `UserSettingsFactory`: defaults `currency` to `EUR`.

TASK-2: Add the update endpoint
- New `UpdateCurrencyRequest`: `currency => required|string|in:EUR,USD,GBP,CHF,CAD,AUD,RUB`.
- `UserSettingsService::updateCurrency()`.
- `UserSettingsController::updateCurrency()` with full `@OA\Put` annotation
  (request-body enum + response), message `Currency updated successfully.`.
- Route `PUT currency` in the `me/settings` group in `routes/api.php`.

TASK-3: Expose the setting and cover it with tests
- `UserSettingsResource`: returns `currency` with `?? 'EUR'` fallback; added
  the `UserSettings` `@OA\Schema` property with the 7-code enum.
- `UserApiTest`: update persists, invalid code returns a `currency` validation
  error and keeps the stored value, default `EUR` returned by `/users/me`.

## Migration

Shown for review — **not run against a real database**.

```php
Schema::table('user_settings', function (Blueprint $table) {
    $table->string('currency', 3)->default('EUR')->after('week_start');
});

// down()
Schema::table('user_settings', function (Blueprint $table) {
    $table->dropColumn('currency');
});
```

## Verification

- `composer test` — 2420 passed (9469 assertions), exit 0.
- `composer analyse` — `[OK] No errors`, exit 0.

## Known issues / follow-ups

- No list endpoint by contract: the app hardcodes the same 7 ISO codes; the
  API stays authoritative for validation.
- The 7 codes appear literally in the request rule and the two OpenAPI
  annotations. That matches the existing `week_start` / `temperature_unit`
  style and keeps the diff honest; no shared constant was introduced.
- Do not run `php artisan migrate` here: the migration above needs the human
  to see it first.
- Environment only: `.env.testing` is a `/dev/null` character device in this
  worktree, so it shows as modified in `git status` and must not be committed.
  A local empty `.env` was created (gitignored) to silence the dotenv read
  warning; neither is part of the change.
