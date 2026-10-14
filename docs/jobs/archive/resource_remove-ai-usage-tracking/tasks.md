# Tasks: Remove AI usage tracking

id: resource
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

Branch verified: worktree HEAD is `feature/resource_remove-ai-usage-tracking`, which matches `brief.md`.

## What I checked

- A grep for `AiUsage|ai_usage|assistant_chat|ai_tokens|saveUsageForUser` across `app/`, `tests/`, `database/`, `config/` and the phpstan config matches the brief's line numbers exactly. No factory, seeder, config, phpstan baseline or `User` relation refers to `AiUsage`.
- The `Statistics` OpenAPI schema (`app/Api/Statistics/Resources/StatisticsResource.php`) is `@OA\AdditionalProperties` only and does **not** list `ai_tokens`. So the brief's "if it is listed there" OpenAPI step needs no change.
- `app/Api/Statistics/Tests/StatisticsTest.php` and `tests/Unit/ClientContractTest.php` don't assert `ai_tokens`, so neither needs a change.
- `MigrationCompatibilityTest` scans only `up()`. A `Schema::dropIfExists` in `up()` gets flagged, so the allowlist entry is required. The baseline count (`144`) is unaffected.
- The latest migration is `2026_10_14_000001_add_client_version_to_personal_access_tokens.php`.

## Task breakdown

TASK-1: Before removing `ai_tokens`, grep the 1.0.15 frontend (`solyto/app` at tag `1.0.15`, read-only) for `ai_tokens`. If it reads the field, stop and report (rule 13.4) instead of going on with TASK-4.
  files: none (read-only check; record the result in `implementation.md`)
  depends: none
  risk: low. It's only a check, but it gates TASK-4. I couldn't run it: the frontend repo isn't in `/workspace` and I didn't fetch it.

TASK-2: Remove the `saveUsageForUser(...)` call (`:127`) and the `AiUsageFeatureEnum` import (`:7`) from `LibraryRecommender`, and remove the matching `shouldReceive('saveUsageForUser')` expectation (`:153`) from `LibraryServiceTest`.
  files: app/Api/Libraries/Services/LibraryRecommender.php, app/Api/Libraries/Tests/LibraryServiceTest.php
  depends: none
  risk: low. `new()` still returns `$response` unchanged; only a side effect goes away.

TASK-3: In `AiService`, delete `saveUsageForUser()`, the `$inputTokens` / `$outputTokens` / `$totalTokens` properties and the three lines that fill them in both `respond()` and `respondStructured()`, and the now-unused imports (`User`, `AiUsageFeatureEnum`, `AiUsage`). In `LibraryServiceTest`, delete the `saveUsageForUser persists the tracked usage` test (`:73-87`) and the `AiUsageFeatureEnum` / `AiUsage` imports (`:9-10`).
  files: app/Api/Libraries/Services/AiService.php, app/Api/Libraries/Tests/LibraryServiceTest.php
  depends: TASK-2
  risk: low. The request logic stays as it is (out of scope). See the note below on `$this->model = $response->model`.

TASK-4: Remove `'ai_tokens' => AiUsage::sum('total_tokens')` (`:70`) and the `AiUsage` import (`:27`) from `StatisticsService::overview()`. The OpenAPI schema needs no change (verified above).
  files: app/Api/Statistics/Services/StatisticsService.php
  depends: TASK-1 (only if the 1.0.15 frontend doesn't read `ai_tokens`)
  risk: low. The endpoint is admin-only, and no test or contract fixture asserts the field.

TASK-5: Delete `app/Shared/Models/AiUsage.php` and `app/Shared/Enums/AiUsageFeatureEnum.php`. In `tests/Unit/EnumsTest.php`, remove the `AiUsageFeatureEnum` describe block (`:31-36`), its import (`:5`) and its entry in `covers(...)` (`:8`).
  files: app/Shared/Models/AiUsage.php (delete), app/Shared/Enums/AiUsageFeatureEnum.php (delete), tests/Unit/EnumsTest.php
  depends: TASK-2, TASK-3, TASK-4 (all references must be gone first)
  risk: low. The grep shows no other references.

TASK-6: Add one new migration (suggested name `2026_10_14_000002_drop_ai_usage_table.php`, after the latest existing one). Its `up()` is `Schema::dropIfExists('ai_usage')`. Its `down()` recreates the table exactly like `2025_10_26_025059_create_ai_usage_table.php`: id, `date` dateTime, `feature`/`model` strings, three integer token columns, and a `user_id` uuid FK to `users` with cascade delete. Add the filename to `MIGRATION_COMPATIBILITY_ALLOWLIST` in `tests/Unit/MigrationCompatibilityTest.php` with the reason "`remove-ai-usage`: no supported client reads `ai_usage`".
  files: database/migrations/2026_10_14_000002_drop_ai_usage_table.php (new), tests/Unit/MigrationCompatibilityTest.php
  depends: none (can be done in parallel; should land with TASK-5)
  risk: medium. This is a destructive production migration: the token data is lost for good, and `down()` only brings back an empty table. Show it to the owner before running `php artisan migrate` (CLAUDE.md hard rule). Running the test suite against in-memory SQLite is fine.

TASK-7: Final check. Grep for `AiUsage`, `ai_usage`, `AiUsageFeatureEnum`, `assistant_chat` and `ai_tokens`, then run `composer test` and `composer analyse`. Both must exit 0.
  files: none
  depends: TASK-1 through TASK-6
  risk: low. Expect more grep hits than the brief lists (see the note below). Don't "fix" them without asking.

## Notes / unclear points

- **`$this->model = $response->model` (brief item 2):** once `saveUsageForUser()` is gone, the only thing that reads `$this->model` after that assignment is the `'model' => $this->model` of a later `create()` call on the same `AiService` instance. Keeping the line would silently replace the configured model with the provider's resolved name (for example a dated snapshot) on that later call. I read the line as having existed only to feed usage tracking, so I recommend removing both assignments (`:55`, `:78`). The developer should still say in `implementation.md` that they made this call. `private string $model` itself stays, because the constructor and `create()` use it.
- **Grep leftovers beyond the brief's list (TASK-7):** `ai_tokens` also matches `2025_09_05_192442_create_ai_tokens_table.php` and `2025_09_08_162738_delete_ai_tokens_table.php`, an older and unrelated table with the same name. It also matches `docs/reports/bot-traffic-detection-2026-10-14.md`, the `docs/backlog/` entries (`remove-ai-usage.md`, `remove-dead-service-config.md`, `api-traffic-alert.md`) and this job's files. These are history and docs, not code. Leave them alone.
- **Backlog entry `docs/backlog/remove-ai-usage.md`:** the brief doesn't say whether this entry should be deleted or marked done once the job is finished. I left that out of scope; the owner or the pipeline should decide.
- **Test names:** `respond returns the chat message content and tracks usage` (`LibraryServiceTest.php:46`) and `new() calls the AI service with favorites and saves usage` (`:145`) will describe behaviour that no longer exists. Renaming them isn't in the brief. It's optional, and I've left it as a reviewer call rather than a task.
- **`fakeChatResponse()`'s `$usage` parameter** stays: the OpenAI response object needs a `usage` block no matter what.
