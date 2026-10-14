# Tasks: User plugins storage and endpoints

id: content
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Preconditions checked

- Branch: worktree HEAD is `feature/content_user-plugins-storage-and-endpoints`, which matches `brief.md`.
- `ai-provider-model-snapshot` is already in place: `app/Plugins/Ai/Enums/AiProviderEnum.php` (`openai`, `openrouter`, `mistral`), `app/Plugins/Ai/Services/AiModelSnapshot.php` (`models()`, `has()`), and `resources/data/ai-models.json`.
- `remove-user-openai-key` is already in place: `database/migrations/2026_10_14_000002_drop_openai_api_key_and_ai_enabled_from_user_settings.php`. No `openai_api_key` is left in `app/`. So there is only one place that holds a user AI key.
- Some of the `app/Plugins` wiring already exists:
  - `phpunit.xml` has a `Plugins` suite, but only for `app/Plugins/*/Tests`.
  - `tests/Pest.php` already applies `TestCase` and `RefreshDatabase` to `../app/Plugins`.
  - `phpstan.neon` already excludes `app/*/Tests/*` and `app/Plugins/*/Tests/*`.
- **Not yet wired:**
  - The OpenAPI generator only scans `app/OpenApi` and `app/Api`. That applies to `config/l5-swagger.php:46-49` and to `tests/Unit/OpenApiSpecTest.php:44-47`.
  - `tests/Unit/ResourceSchemaDriftTest.php:147` only globs `app/Api/*/Resources`.
  - Without the changes in TASK-8, `@OA` annotations in `app/Plugins/` would not appear in the spec.
- `users.id` is a UUID (`0001_01_01_000000_create_users_table.php:15`). `AiProviderEnum` has no display name yet, but the providers endpoint needs `"name": "OpenAI"`.

## Unclear points (resolve before or during TASK-5, do not guess silently)

1. **Are `provider` and `model` required on `PUT users/me/plugins/ai`?** The brief lists them but doesn't say whether they're required. `model` can only be validated together with a provider.
   - Proposed default: `provider` and `model` are `required` (PUT is a full upsert).
   - Alternative: `sometimes`. Then `model` is validated against the payload provider, falling back to the stored one, and `model` is required when `provider` changes.
   - The owner should confirm.
2. **Is `credentials` nullable?** The brief says "text, encrypted" but not whether it's nullable. Future plugins may have no credentials, so the proposal is `nullable()`. For `ai`, the request still enforces a key on first save.
3. **What do the single GET and the list return for a plugin with no row?** The brief says the list shows `enabled: false, has_api_key: false` "in the same shape as the single endpoint". Proposal:
   - `GET users/me/plugins/ai` with no row returns 200 with those defaults, `settings: null` and `model_available: false`. It does not return 404.
   - 404 is only for an unknown `{plugin}`.
4. **Is `model_available` AI-specific?** The resource is shared, but `model_available` only means something for AI. Proposal: the field is always present, computed by the service per plugin through a `match` on `PluginEnum` (rule 4.3).
5. **Is the backlog entry closed in this job?** `docs/backlog/user-plugins-storage.md` exists. Closing or updating it is left to the pipeline/owner and is not a task here.

## Task breakdown

TASK-1: Add the backed enum `PluginEnum` with the single case `AI = 'ai'`.
  files: app/Plugins/Enums/PluginEnum.php (new)
  depends: none
  risk: low — new, isolated file.

TASK-2: Add a display-name method (e.g. `label()`: OpenAI / OpenRouter / Mistral) to `AiProviderEnum` for the providers endpoint, as a `match` like the existing `baseUrl()`.
  files: app/Plugins/Ai/Enums/AiProviderEnum.php
  depends: none
  risk: low — additive method on an enum that already has a method of the same shape.

TASK-3: Add the additive migration that creates `user_plugins`.
  - Columns:
    - `id`: auto-increment, which is the settings-style integer key (rule 5.4). The table is only reached through `users/me` plus the enum, so ids are never exposed or taken from the payload.
    - `user_id`: `foreignUuid`, constrained, `cascadeOnDelete`.
    - `plugin`: string.
    - `enabled`: boolean, default false.
    - `credentials`: text, nullable per unclear point 2.
    - `settings`: json, nullable.
    - timestamps.
    - unique (`user_id`, `plugin`).
  - Show the migration to the owner before running it (hard rule). No allowlist entry should be needed. Confirm this with `tests/Unit/MigrationCompatibilityTest.php`.
  files: database/migrations/2026_10_14_xxxxxx_create_user_plugins_table.php (new)
  depends: none
  risk: medium — schema change on the production MariaDB. It's additive, but it needs owner sign-off before running, and the cascade FK must also work under SQLite in tests.

TASK-4: Add the `UserPlugin` model and the `UserPluginFactory` (rules 5.1–5.3, 12.1).
  - Model:
    - `$fillable`.
    - `protected $casts`: `credentials` → `encrypted:array`, `settings` → `array`, `enabled` → `boolean`, `plugin` → `PluginEnum`.
    - Typed `user(): BelongsTo`.
    - `scopeForUser(Builder $query, string $userId)`.
    - `newFactory()`.
  - Factory: default owner `User::factory()`, a `forUser(User $user)` state, plugin `ai`, and sample settings from the snapshot.
  files: app/Plugins/Models/UserPlugin.php (new), app/Plugins/Factories/UserPluginFactory.php (new)
  depends: TASK-1, TASK-3
  risk: low — follows the `Todo`/`TodoFactory` reference shape. Casting `plugin` to the enum is a small addition beyond the brief's list. Drop it if the reviewer prefers a plain string.

TASK-5: Add the FormRequest `UpdateUserPluginRequest` (rule 3.1, pipe strings, arrays only where a rule object is needed).
  - Rules for `ai`:
    - `enabled`: boolean.
    - `api_key`: `required` only when the user has no stored, decryptable key, otherwise `sometimes`; `string`.
    - `provider`: `Rule::enum(AiProviderEnum::class)`.
    - `model`: a closure or `Rule::in` against `AiModelSnapshot::models()` for the given provider.
  - No `authorize()` (rule 3.3) and no behaviour beyond `rules()`/`attributes()`/`messages()` (rule 3.4).
  - Required-ness of `provider`/`model` per unclear point 1.
  - Deciding "has a stored key" needs the user's row. Read it via `UserPlugin::forUser(...)`, or ask the service, inside `rules()`. Keep that read side-effect free.
  files: app/Plugins/Requests/UpdateUserPluginRequest.php (new)
  depends: TASK-1, TASK-4
  risk: medium — the conditional `api_key` requirement and the snapshot-backed `model` rule are the core validation logic, and unclear point 1 affects them.

TASK-6: Add `UserPluginService` (rules 4.1–4.8). It takes `User $user` per method, injects `AiModelSnapshot`, and has these methods:
  - `list(User $user)`: one entry per `PluginEnum` case, merging existing rows with defaults.
  - `find(User $user, PluginEnum $plugin)`: the row or an unsaved default.
  - `update(User $user, PluginEnum $plugin, array $data)`: `updateOrCreate` on (`user_id`, `plugin`). `api_key` goes into `credentials`, and a missing `api_key` keeps the stored credentials. `provider`/`model` go into `settings`.
  - `hasApiKey(UserPlugin $row)`: catches `Illuminate\Contracts\Encryption\DecryptException` by type (rule 4.8, brief) and returns false.
  - `modelAvailable(UserPlugin $row)`: uses `AiModelSnapshot::has()` and is false when no settings are stored, through a `match` on `PluginEnum`.
  - If a decrypt fails during update and no new key is sent, the request (TASK-5) already requires `api_key`, so the old ciphertext gets overwritten.
  files: app/Plugins/Services/UserPluginService.php (new)
  depends: TASK-1, TASK-2, TASK-4
  risk: medium — the `encrypted:array` cast throws on attribute access. Every read of `credentials` must go through the guarded helper, otherwise a lost `APP_KEY` turns into a 500.

TASK-7: Add the resources and the controller, and register the routes.
  - `UserPluginResource`: `plugin`, `enabled`, `has_api_key`, `settings` (`provider`, `model`), `model_available`.
    - Never `credentials` or `api_key`.
    - The computed values come from the service. Pass them in; the resource itself does no decrypting or snapshot lookups.
  - `AiProviderResource` (or a resource for the provider list): `provider`, `name`, `models[{id, name}]`.
  - `UserPluginController`, with actions `index`, `show`, `update`, `providers`. Each action does delegate + `ApiResponse::success` (rule 1.1).
  - `{plugin}` uses implicit enum route binding to `PluginEnum`, so an unknown value returns 404 through the central map.
  - Routes in `routes/api.php`, `auth:sanctum` group, rule 1.4:
    - In the `users` prefix block (`routes/api.php:103-124`), a `me/plugins` sub-group with `GET ''`, `GET {plugin}`, `PUT {plugin}`.
    - A separate `Route::prefix('plugins')` block with `GET ai/providers`.
  - `@OA` annotations per section 2:
    - Paths are relative (`/users/me/plugins`, …).
    - `allOf` SuccessResponse.
    - 422 → `ValidationErrorResponse`.
    - 404 for an unknown plugin.
    - Unique `operationId`s (e.g. `userPluginIndex`, `userPluginShow`, `userPluginUpdate`, `aiProviderIndex`).
    - The `@OA\RequestBody` matches the TASK-5 rules (rule 2.6).
    - The resource `@OA\Schema` lists `has_api_key`, never `api_key`.
  files: app/Plugins/Controllers/UserPluginController.php (new), app/Plugins/Resources/UserPluginResource.php (new), app/Plugins/Resources/AiProviderResource.php (new; could also live in app/Plugins/Ai/Resources, the reviewer decides), routes/api.php
  depends: TASK-5, TASK-6
  risk: medium — touches the shared route file and has to follow rules 1.1, 1.4 and 2.x. Enum route binding must yield the standard 404 envelope.

TASK-8: Include `app/Plugins` in the OpenAPI and resource-schema checks.
  - Add `base_path('app/Plugins')` to `config/l5-swagger.php` annotations and to the generator paths in `tests/Unit/OpenApiSpecTest.php`.
  - Extend the glob in `tests/Unit/ResourceSchemaDriftTest.php` to `app/Plugins/**/Resources`.
  - Without this, the new annotations are invisible to the spec and to rule 2.2/2.6 checks.
  files: config/l5-swagger.php, tests/Unit/OpenApiSpecTest.php, tests/Unit/ResourceSchemaDriftTest.php
  depends: TASK-7
  risk: low — additive paths. Possible side effects: duplicate `operationId`/schema names, and `OPENAPI_KNOWN_DRIFT` must stay exactly 1 entry. The brief only says "per section 2", so the reviewer should confirm this wiring is wanted.

TASK-9: Write Pest feature tests in `app/Plugins/Tests/`, and add `<directory>app/Plugins/Tests</directory>` to the `Plugins` testsuite in `phpunit.xml`. Today that suite only matches `app/Plugins/*/Tests`. Pest.php and phpstan already cover the path. Cases:
  - (a) The raw `credentials` column (`DB::table('user_plugins')->value('credentials')`) does not contain the plain key, and it decrypts back to the key.
  - (b) The key string never appears in the bodies of index, show, update and providers.
  - (c) Validation 422s:
    - unknown provider;
    - a model that isn't in the snapshot for the given provider (including a model that is valid for another provider);
    - missing `api_key` on first save;
    - omitting `api_key` later keeps the stored key.
  - (d) `model_available` is false for a stored model that isn't in the snapshot, and the stored value is unchanged. Use a temporary snapshot file bound in the container, like `AiModelSnapshotTest`.
  - (e) User B can't see user A's plugin: B's index and show return defaults, never A's state.
  - (f) Deleting the user deletes the rows (cascade).
  - (g) A value encrypted under a different key gives `has_api_key: false` and no 500. Use `new Encrypter(random key)` to write the column directly.
  - (h) Unknown `{plugin}` → 404.
  - (i) Unauthenticated → 401.
  - (j) The providers endpoint shape.
  - Check with `vendor/bin/pest --list-tests` that the tests show up.
  files: app/Plugins/Tests/UserPluginApiTest.php (new), phpunit.xml
  depends: TASK-7 (and TASK-8 for the spec/drift tests to pass)
  risk: low — tests only, plus one line in `phpunit.xml`. The "different key" test is the subtle one.

TASK-10: Run `composer test` and `composer analyse` in the worktree and fix any findings in the new code until both exit 0. Do not touch the baseline for new code.
  files: any of the above
  depends: TASK-1 … TASK-9
  risk: low — verification only, but it is the job's done criterion.

## Out of scope (from brief)

`AiService` using the plugin (`ai-plugin`), data export, frontend changes, and a separate plugin encryption key.
