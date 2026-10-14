# Brief: Remove the unused per-user OpenAI key

type: feature
id: quite
branch: feature/quite_remove-the-unused-per-user-openai-key
date: 2026-10-14
author: Leander Muskalla

## What
Remove `openai_api_key` and `ai_enabled` from user settings, end to end:

1. **Route:** `Route::put('openai-api-key', ...)` in `routes/api.php:116`.
2. **Controller:** `UserSettingsController::updateOpenaiApiKey()` with its `@OA\Put` block (`app/Api/Users/Controllers/UserSettingsController.php:291-325`) and the `UpdateOpenaiApiKey` import (`:14`).
3. **Request:** delete `app/Api/Users/Requests/UpdateOpenaiApiKey.php`.
4. **Service:** `UserSettingsService::updateOpenaiApiKey()` (`app/Api/Users/Services/UserSettingsService.php:51-57`).
5. **Resource:** `ai_enabled` and `openai_api_key` in `UserSettingsResource` (`toArray` `:41-42` and the `@OA\Property` lines `:17-18`, rule 2.6).
6. **Model:** both fields in `UserSettings::$fillable` (`:28-29`) and the `ai_enabled` cast (`:43`).
7. **Factory:** both fields in the default definition (`UserSettingsFactory.php:23-24`) and the `withAiEnabled()` state (`:40-46`).
8. **Tests:** the two tests `enables AI when an API key is provided` / `disables AI when the API key is cleared` in `app/Api/Users/Tests/UserApiTest.php` (around `:246-270`), and `creates settings with AI enabled` in `app/Api/Users/Tests/UserTest.php` (around `:164-169`).
9. **Migration:** one new migration that drops `openai_api_key` and `ai_enabled` from `user_settings`. `down()` adds them back (`ai_enabled` boolean default `false`, `openai_api_key` nullable string). Add it to `MIGRATION_COMPATIBILITY_ALLOWLIST` in `tests/Unit/MigrationCompatibilityTest.php` with the reason "`remove-user-openai-key`: 1.0.15 doesn't read either column (owner, 2026-10-14)".

## Why
Nothing reads these values. `PUT users/me/settings/openai-api-key` stores the key and sets `ai_enabled`, but `AiService` only uses the instance key (`config('services.ai.api_key')`). Meanwhile the key is stored in plain text and returned by `GET` user settings. The AI plugin (`user-plugins-storage`, `ai-plugin`) replaces this with encrypted per-user credentials that are never returned. Existing values are dropped, not migrated: they were never used, and they have no provider or model attached. Owner decision 2026-10-14.

## Out of scope
- The new plugin storage and endpoints (`user-plugins-storage`).
- Other user settings fields and endpoints.

## Notes
- The owner confirmed (2026-10-14) that the 1.0.15 frontend uses neither the route nor the two fields; the last frontend to use them is much older. So no stub period under rule 13.4.
- Show the migration to the owner before running it (CLAUDE.md hard rule).
- Afterwards, grep for `openai_api_key`, `ai_enabled`, `OpenaiApiKey` and `openai-api-key`. The only hits left should be old migrations, the new drop migration, its allowlist entry and `tests/Fixtures/client-contract/1.0.15-migrations.txt`.

Check: `composer test` and `composer analyse` exit 0.
