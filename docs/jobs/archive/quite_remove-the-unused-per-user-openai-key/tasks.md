# Tasks: Remove the unused per-user OpenAI key

id: quite
analyst: analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Contract-test conflict (resolved)

The brief says 1.0.15 doesn't use the route or the fields. Two checked-in
contract pins say otherwise:

- `tests/Fixtures/client-contract/1.0.15-routes.json:936-939` lists
  `PUT api/v1/users/me/settings/openai-api-key`. The test `still registers
  every route 1.0.15 calls` (`tests/Unit/ClientContractTest.php:104`)
  requires it.
- `tests/Unit/ClientContractTest.php:177` requires `settings.openai_api_key`
  on `GET users/me`. `ai_enabled` is not pinned.

Resolution (Leander Muskalla, 2026-10-14): 1.0.15 declares the route and the
field (`apiRoutes.ts`, `types/auth.ts`) but never actually uses the key. The
pins are broader than real usage, so they are narrowed as part of this job
(TASK-1). Under rule 13.6 this is a deliberate owner decision, not a "fix the
red test" move. The reviewer should check that TASK-1 changes nothing beyond
these two entries.

## Task breakdown

TASK-1: Narrow the 1.0.15 client contract. Remove the `PUT
api/v1/users/me/settings/openai-api-key` entry from the routes fixture, and
remove `'openai_api_key'` from the `settings` key list in the `users/me`
contract test.
files: tests/Fixtures/client-contract/1.0.15-routes.json,
tests/Unit/ClientContractTest.php
depends: none
risk: medium. It loosens the safety net that protects 1.0.15, so it rests
entirely on the owner's statement above. Keep the JSON valid and touch no
other entry.

TASK-2: Remove the route, the controller action and the request. That means
`Route::put('openai-api-key', ...)` in `routes/api.php` (currently line 117,
not 116), `UserSettingsController::updateOpenaiApiKey()` with its `@OA\Put`
block and the `UpdateOpenaiApiKey` import, and the file
`app/Api/Users/Requests/UpdateOpenaiApiKey.php`.
files: routes/api.php, app/Api/Users/Controllers/UserSettingsController.php,
app/Api/Users/Requests/UpdateOpenaiApiKey.php (delete)
depends: TASK-1 (otherwise the route contract test goes red)
risk: low. This is the only caller, and grep finds no other references.

TASK-3: Remove `UserSettingsService::updateOpenaiApiKey()`.
files: app/Api/Users/Services/UserSettingsService.php
depends: TASK-2
risk: low. Its only caller is the controller action removed in TASK-2.

TASK-4: Remove `ai_enabled` and `openai_api_key` from `UserSettingsResource`,
both the `toArray()` entries and the two `@OA\Property` lines (rule 2.6). Also
delete the two now-unmatched `phpstan-baseline.neon` entries for
`UserSettingsResource::$ai_enabled` (around line 3405) and `::$openai_api_key`
(around line 3453).
files: app/Api/Users/Resources/UserSettingsResource.php, phpstan-baseline.neon
depends: TASK-1 (otherwise the users/me contract test goes red)
risk: low. Without the baseline edit, `composer analyse` fails on unmatched
ignored errors. This is the only baseline change: don't regenerate the
baseline.

TASK-5: Remove both fields from `UserSettings::$fillable` and the
`ai_enabled` cast. In `UserSettingsFactory`, remove them from the default
definition and delete the `withAiEnabled()` state.
files: app/Api/Users/Models/UserSettings.php,
app/Api/Users/Factories/UserSettingsFactory.php
depends: TASK-6 (the test that uses `withAiEnabled()` must go first, or at
the same time)
risk: low. The factory must stop writing columns that TASK-7 drops, otherwise
every test that creates `UserSettings` fails.

TASK-6: Delete the tests `enables AI when an API key is provided` and
`disables AI when the API key is cleared` (`UserApiTest.php:246-269`), and
`creates settings with AI enabled` (`UserTest.php` around 164-169).
files: app/Api/Users/Tests/UserApiTest.php, app/Api/Users/Tests/UserTest.php
depends: none (do it together with TASK-2 and TASK-5)
risk: low. These test only the behaviour that is being removed.

TASK-7: Add one migration that drops `openai_api_key` and `ai_enabled` from
`user_settings` on the default connection. `down()` adds back `ai_enabled`
(boolean, default `false`) and `openai_api_key` (nullable string). Name it
after the latest migration, e.g. `2026_10_14_000002_drop_openai_api_key_and_ai_enabled_from_user_settings.php`.
Add it to `MIGRATION_COMPATIBILITY_ALLOWLIST` in
`tests/Unit/MigrationCompatibilityTest.php` with the reason
"`remove-user-openai-key`: 1.0.15 doesn't read either column (owner,
2026-10-14)". **Show the migration to the owner before running it.** Don't
touch the `pgsql` connection.
files: database/migrations/<new>.php, tests/Unit/MigrationCompatibilityTest.php
depends: TASK-5
risk: medium. It is destructive: stored keys are lost and `down()` can't
bring them back. This is intended per the brief. Also check that SQLite
handles dropping both columns in the test run.

TASK-8: Verify. Grep for `openai_api_key`, `ai_enabled`, `OpenaiApiKey`,
`openai-api-key` and `withAiEnabled`. Outside `docs/` (backlog, jobs,
archive), the only hits allowed are the two old migrations, the new
migration, its allowlist entry and `1.0.15-migrations.txt`. Then run
`composer test` and `composer analyse`. Both must exit 0.
files: none
depends: TASK-1 to TASK-7
risk: low. Verification only.

## Notes / unclear

- The brief's line numbers are slightly off (e.g. the route is at
  `routes/api.php:117`). Developers should match on content, not on line
  numbers.
- The archived job `minor_client-contract-tests-for-frontend-1-0-15`
  recorded `openai_api_key` as a key "1.0.15 reads". If 1.0.15 does
  dereference `settings.openai_api_key` (e.g. to pre-fill an input), it will
  get `undefined` after this change. The owner accepted this. It's noted here
  so the reviewer doesn't flag it as an unknown.
- `docs/backlog/remove-user-openai-key.md` has the same content as this
  brief. Whether to delete or close it once the job lands is the owner's
  call. It's not one of the tasks.
