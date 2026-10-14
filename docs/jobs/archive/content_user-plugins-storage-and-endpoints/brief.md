# Brief: User plugins storage and endpoints

type: feature
id: content
branch: feature/content_user-plugins-storage-and-endpoints
date: 2026-10-14
author: Leander Muskalla

## What
Per-user plugin configuration, with AI as the first and only plugin.

1. **Plugin list:** a backed enum of plugins, with one case: `ai`.
2. **Table `user_plugins`:** `user_id` (uuid, FK to `users`, cascade on delete), `plugin` (string), `enabled` (boolean, default `false`), `credentials` (text, encrypted), `settings` (json), timestamps. Unique on (`user_id`, `plugin`). UUID vs auto-increment id per rule 5.4.
3. **Model `UserPlugin`:** `$fillable`, casts (`credentials` → `encrypted:array`, `settings` → `array`, `enabled` → `boolean`), `user()` relation, `scopeForUser`, factory with `forUser` (rules 5.1–5.3).
4. **Endpoints** in the `auth:sanctum` group, under the existing `users` prefix next to `me/settings` (`routes/api.php:102-108`; owner, 2026-10-14):
   - `GET users/me/plugins` returns every plugin in the enum with its state for the current user, in the same shape as the single endpoint. A plugin without a row shows as `enabled: false`, `has_api_key: false` (owner, 2026-10-14).
   - `GET users/me/plugins/{plugin}` returns `enabled`, `has_api_key` (bool), `settings` (`provider`, `model`) and `model_available` (bool, from the `ai-provider-model-snapshot` list). It **never** returns credentials.
   - `PUT users/me/plugins/{plugin}` creates or updates the user's row. For `ai`, the FormRequest validates:
     - `enabled`: boolean
     - `api_key`: required string when there is no stored key yet. Leaving it out keeps the stored key.
     - `provider`: one of the provider enum cases
     - `model`: a model in the snapshot for that provider
   - `GET plugins/ai/providers` returns the providers and their models from the snapshot: `[{"provider": "openai", "name": "OpenAI", "models": [{"id": ..., "name": ...}]}, ...]`. Same for every user, so it's not under `users/me`. Still in the `auth:sanctum` group (owner, 2026-10-14).
   - Unknown `{plugin}` → 404.
   - `@OA` annotations per section 2. The resource schema shows `has_api_key`, never `api_key`.
5. **Tests:**
   - the key is stored encrypted (the raw column isn't the plain value)
   - the key never appears in any response
   - validation for provider and model
   - `model_available` is `false` when the stored model isn't in the snapshot
   - one user can't see another user's plugin
   - rows are deleted with the user

## Why
Concept, agreed with the owner on 2026-10-14: a **plugin** is something a user chooses to turn on, with their own credentials if needed. It's separate from **integrations**, which are instance-wide (`integrations-endpoint`). A dedicated table instead of more `user_settings` columns, so future plugins reuse the same storage and endpoints. It replaces the old plain-text `openai_api_key` (`remove-user-openai-key`).

Behavior agreed for AI (point 3f): if the stored model disappears from the snapshot, the stored value stays, `model_available` is `false`, and the frontend asks the user to pick a new model. Nothing switches automatically.

## Out of scope
- Making `AiService` use this (`ai-plugin`).
- Data export. The export contains content only, no settings, so plugins stay out of it too (owner, 2026-10-14). Account deletion is covered by the cascade.
- Frontend changes.

Undecryptable credentials (owner, 2026-10-14): if `APP_KEY` is lost, stored keys can't be decrypted. Catch the decryption error (`DecryptException`, rule 4.8) and treat the plugin as not configured: `has_api_key: false`, no AI request, the user re-enters the key. No separate plugin encryption key. Planned rotations work through Laravel's `APP_PREVIOUS_KEYS`. Add a test with a value encrypted under a different key.

## Where it lives
Owner decision 2026-10-14: plugins are not modules. They live in `app/Plugins/`, a peer of `app/Api/` like `app/Bots/` and `app/Dav/` (rule 12.1):
- `app/Plugins/`: shared plugin infrastructure, i.e. `PluginEnum`, the `UserPlugin` model and factory, the plugin controller, requests and resources.
- `app/Plugins/Ai/`: everything AI-specific (provider enum, snapshot, `AiService` once `ai-plugin` moves it).

Modules may call plugin services. Rule 12.8 (no module calls another module's service) doesn't apply to plugins. Recorded under `## Decisions` in `docs/STANDARDS.md` (2026-10-14).

Wiring the new folder up:
- **Tests:** `app/Plugins/**/Tests` is not picked up today. Add a testsuite to `phpunit.xml` (the `Api` suite only covers `app/Api/*/Tests`) and add the path to `uses(TestCase::class)` and `uses(RefreshDatabase::class)` in `tests/Pest.php`. Check with `vendor/bin/pest --list-tests` that the new tests actually show up.
- **PHPStan:** `phpstan.neon` excludes `app/*/Tests/*` and `app/Api/*/Tests/*`. Exclude `app/Plugins/*/Tests/*` as well if tests sit one level deeper.
- **Routes:** the routes stay in `routes/api.php` (rule 12.2), importing the controller from `app/Plugins/`.

## Notes
Depends on `ai-provider-model-snapshot` (validation, `model_available`). Do `remove-user-openai-key` first or in the same job, so there are never two places holding a user AI key.

Migration is additive (new table), so no allowlist entry needed. Show it to the owner before running it anyway (CLAUDE.md hard rule).

Check: `composer test` and `composer analyse` exit 0.
