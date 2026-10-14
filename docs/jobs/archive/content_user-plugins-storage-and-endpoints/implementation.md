# Implementation: User plugins storage and endpoints

id: content
developer: @developer
date: 2026-10-14

## Summary

Per-user plugin storage (`user_plugins`) and the endpoints `GET users/me/plugins`, `GET|PUT users/me/plugins/{plugin}` and `GET plugins/ai/providers`. AI is the only plugin. Everything lives in `app/Plugins/`, and the AI-specific resource is in `app/Plugins/Ai/`. The API key is stored with the `encrypted:array` cast and is never returned. A key that can't be decrypted (lost `APP_KEY`) counts as "not configured": `has_api_key: false`, and the user must send a new key. `composer test` and `composer analyse` both exit 0.

For the analyst's unclear points I used the proposed defaults:
1. On `PUT users/me/plugins/ai`, `provider` and `model` are `required`. `enabled` is also `required` (PUT is a full upsert).
2. `credentials` is nullable.
3. A plugin with no row returns 200 with defaults: `enabled: false`, `has_api_key: false`, `settings: null`, `model_available: false`. 404 is only returned for an unknown `{plugin}`.
4. `model_available` is always present. The service computes it through a `match` on `PluginEnum`.
5. The backlog entry is not touched.

## Changes

TASK-1: `app/Plugins/Enums/PluginEnum.php`: a backed enum with the single case `AI = 'ai'`.

TASK-2: `app/Plugins/Ai/Enums/AiProviderEnum.php`: added `label()` (OpenAI / OpenRouter / Mistral), used for `name` in the providers endpoint.

TASK-3: `database/migrations/2026_10_14_000003_create_user_plugins_table.php`:
- Columns: auto-increment `id` (rule 5.4, a settings-style table that never exposes its id), `user_id` (foreignUuid with cascade on delete), `plugin`, `enabled` (default false), `credentials` (nullable text), `settings` (nullable json), timestamps.
- Unique on (`user_id`, `plugin`).
- The migration is additive, and `MigrationCompatibilityTest` passes without an allowlist entry.
- It was **not** run against any real database, only against the in-memory SQLite used by tests. It needs the owner's review before `php artisan migrate`.

TASK-4: `app/Plugins/Models/UserPlugin.php` and `app/Plugins/Factories/UserPluginFactory.php`:
- Model: `$fillable`, `$casts` (`plugin` → `PluginEnum`, `enabled` → boolean, `credentials` → `encrypted:array`, `settings` → array), `user()`, `scopeForUser`, `newFactory()`.
- Factory: `User::factory()` as the default owner, a `forUser` state, and the first OpenAI model from the bound snapshot.

TASK-5: `app/Plugins/Requests/UpdateUserPluginRequest.php`:
- `match` on the bound `PluginEnum`.
- `api_key` is `required` unless the service reports a readable stored key, otherwise `sometimes`.
- `provider` uses `Rule::enum(AiProviderEnum)`.
- `model` uses `Rule::in` with the snapshot models of the provider in the payload.
- The service and the snapshot come in through method injection on `rules()` (FormRequest resolves `rules()` through the container). The request has no other methods besides `attributes()`.

TASK-6: `app/Plugins/Services/UserPluginService.php`:
- Methods: `list`, `find`, `update`, `aiProviders`, `hasApiKey`.
- Reads and update return a state array (`plugin`, `enabled`, `has_api_key`, `settings`, `model_available`), so the resource never decrypts anything or looks anything up.
- Every read of `credentials` goes through a guard that catches `DecryptException` (rule 4.8).
- `update` also handles one edge case: Eloquent's dirty check decrypts the *old* value when `credentials` is overwritten, so re-entering a key over an undecryptable value would throw. In that case the original is reset to null first. There is a why-comment, and a test fails without it (verified).

TASK-7:
- `app/Plugins/Controllers/UserPluginController.php`: `index`, `show`, `update`, `providers`. Each one delegates and wraps; there is no bound model, so there is no ownership check.
- `app/Plugins/Resources/UserPluginResource.php`: schema `UserPlugin`, with `has_api_key` and never `api_key`.
- `app/Plugins/Ai/Resources/AiProviderResource.php`: schema `AiProvider`.
- `routes/api.php`:
  - A `me/plugins` sub-group inside the `users` prefix.
  - A new `plugins` prefix block with `ai/providers`.
  - `{plugin}` uses implicit enum binding, so an unknown value becomes a 404 envelope.
- `@OA` annotations: relative paths, `allOf SuccessResponse`, 404/422 (`ValidationErrorResponse`), and the operationIds `userPluginIndex`, `userPluginShow`, `userPluginUpdate`, `aiProviderIndex`.

TASK-8: `app/Plugins` is now included in the OpenAPI checks:
- `config/l5-swagger.php` and `tests/Unit/OpenApiSpecTest.php` scan `app/Plugins`.
- `tests/Unit/ResourceSchemaDriftTest.php` also globs `app/Plugins/Resources` and `app/Plugins/*/Resources`.
- `OPENAPI_KNOWN_DRIFT` is unchanged.

TASK-9: `app/Plugins/Tests/UserPluginApiTest.php` (18 tests) and `phpunit.xml` (`app/Plugins/Tests` added to the `Plugins` suite). The tests cover:
- the key is stored encrypted;
- the key never appears in any of the four responses;
- provider and model validation, including a model that only exists for another provider;
- `api_key` is required on first save, and omitting it later keeps the stored key;
- `model_available: false` for a stored model that's not in the snapshot, and the stored value is kept;
- isolation (show, index, and update don't touch another user's row);
- the cascade delete;
- a key encrypted under a different key: `has_api_key: false`, a 422 when no new key is sent, and saving works once a new key is sent;
- 404 for an unknown plugin, 401 without authentication;
- the shape of the providers endpoint.

`vendor/bin/pest --list-tests` lists all 18 tests.

TASK-10: Fixed the PHPStan findings in the new code: a typed `@var` on the bound route enum, the nullsafe-before-`??` findings, and a dead-catch finding (read through `getAttribute`). The baseline is untouched. `composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- Environment: `vendor/` was missing, so I ran `composer install` from the lock file (no new packages). I also created the git-ignored `storage/framework/*` cache directories so artisan could boot.
- In this sandbox, `.env.testing` is masked by a `/dev/null` device. That causes about 860 test *warnings* (`file_get_contents` on `.env.testing`) and the `M .env.testing` entry in `git status`. I left it alone and did not commit it.
- On one early baseline run, before any changes, `app/Api/Export/Tests/ExportTest.php` (`FinanceWealthExportService`) failed twice. Every later run passed. It looks flaky and is unrelated to this job.
- The migration still needs the owner's review before it is run on MariaDB.
- `docs/backlog/user-plugins-storage.md` was not updated or closed (unclear point 5).
