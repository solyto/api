# Implementation: Remove the unused per-user OpenAI key

id: quite
developer: developer
date: 2026-10-14

## Summary

Removed `openai_api_key` and `ai_enabled` from user settings end to end: route, controller action, request, service method, resource fields, model fillable/cast, factory, tests, and a new migration that drops both columns. The 1.0.15 client contract was narrowed first, per the owner decision recorded in tasks.md.

## Changes

TASK-1: Removed the `PUT api/v1/users/me/settings/openai-api-key` entry from `tests/Fixtures/client-contract/1.0.15-routes.json` (JSON still valid) and `'openai_api_key'` from the `users/me` settings key list in `tests/Unit/ClientContractTest.php`. No other entries changed.
TASK-2: Removed `Route::put('openai-api-key', ...)` from `routes/api.php`, removed `UserSettingsController::updateOpenaiApiKey()` with its `@OA\Put` block and the `UpdateOpenaiApiKey` import, and deleted `app/Api/Users/Requests/UpdateOpenaiApiKey.php`.
TASK-3: Removed `UserSettingsService::updateOpenaiApiKey()`.
TASK-4: Removed `ai_enabled` / `openai_api_key` from `UserSettingsResource::toArray()` and its `@OA\Property` lines. Deleted the two matching `phpstan-baseline.neon` entries by hand (no regeneration).
TASK-5: Removed both fields from `UserSettings::$fillable` and the `ai_enabled` cast. Removed them from the `UserSettingsFactory` definition and deleted the `withAiEnabled()` state.
TASK-6: Deleted `enables AI when an API key is provided` and `disables AI when the API key is cleared` (`UserApiTest.php`) and `creates settings with AI enabled` (`UserTest.php`).
TASK-7: Added `database/migrations/2026_10_14_000002_drop_openai_api_key_and_ai_enabled_from_user_settings.php` (default connection). `up()` drops both columns. `down()` re-adds `ai_enabled` (boolean, default false) and `openai_api_key` (nullable string). Added it to `MIGRATION_COMPATIBILITY_ALLOWLIST` with the reason from the brief. **I did not run the migration against any real database. It only ran inside the in-memory SQLite test suite, which handled both drops without problems. The owner needs to see it before it is run (CLAUDE.md hard rule).**
TASK-8: The grep for `openai_api_key|ai_enabled|OpenaiApiKey|openai-api-key|withAiEnabled` outside `docs/`/`.claude/`/`vendor/` finds only the two old migrations, the new migration, its allowlist entry and `1.0.15-migrations.txt`. `composer test` exits 0 (3042 assertions). `composer analyse` exits 0 ("No errors").

## Known issues / follow-ups

- Environment: `vendor/` and `storage/framework/*` were missing in the worktree. I ran `composer install` (from the lock file, no new packages) and created the gitignored `storage/framework/{views,cache/data,sessions,testing}` directories. The first test run had 2 Export test failures from a missing storage subdirectory. They disappeared on the re-run once the directory existed.
- The suite reports ~836 `file_get_contents` warnings. They appear in every test file, including ones this job doesn't touch, so they look environmental. Probably `.env.testing`, which git reports as an "unsupported file type" and shows as modified. This job didn't touch that file, and it wasn't committed.
- After this change, 1.0.15 will see `settings.openai_api_key` as `undefined` (accepted by the owner, see tasks.md).
- `docs/backlog/remove-user-openai-key.md`: delete or close it once this lands (owner's call).
