# Implementation: Remove AI usage tracking

id: resource
developer: @developer
date: 2026-10-14

## Summary

Removed the `ai_usage` setup completely: the model, the feature enum, the usage tracking in `AiService` and `LibraryRecommender`, `ai_tokens` in the admin statistics overview, and the table itself (through a new drop migration). `composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: Frontend check. The 1.0.15 frontend reads `ai_tokens`: it's declared in `src/lib/types/statistic.ts:31`, and the admin statistics page shows it as a tile with a `?? 0` fallback. Following the brief, the work stopped and the question went to the owner. **Owner decision (2026-10-14): remove `ai_tokens` outright. A separate frontend job will stop the frontend from using it.** This is a deliberate exception to rule 13.4. The impact is minimal: the endpoint is admin-only, and because of the `?? 0` fallback, 1.0.15 admins will see a 0 tile instead of an error.

TASK-2: `app/Api/Libraries/Services/LibraryRecommender.php`: removed the `saveUsageForUser(...)` call and the `AiUsageFeatureEnum` import. `new()` now returns the `respondStructured()` result directly. `app/Api/Libraries/Tests/LibraryServiceTest.php`: removed the `shouldReceive('saveUsageForUser')` expectation.

TASK-3: `app/Api/Libraries/Services/AiService.php`: removed `saveUsageForUser()`, the `$inputTokens`/`$outputTokens`/`$totalTokens` properties, the lines in `respond()`/`respondStructured()` that filled them, and the unused imports (`User`, `AiUsageFeatureEnum`, `AiUsage`). **My call:** I also removed both `$this->model = $response->model` assignments. Once tracking was gone, their only effect would have been to silently replace the configured model with the provider's resolved name on a later call on the same instance. `private string $model` stays. `LibraryServiceTest`: deleted the `saveUsageForUser persists the tracked usage` test and its imports.

TASK-4: `app/Api/Statistics/Services/StatisticsService.php`: removed `'ai_tokens' => AiUsage::sum('total_tokens')` and the `AiUsage` import. The OpenAPI schema doesn't list `ai_tokens` (it's `AdditionalProperties` only), so it needed no change.

TASK-5: Deleted `app/Shared/Models/AiUsage.php` and `app/Shared/Enums/AiUsageFeatureEnum.php`. `tests/Unit/EnumsTest.php`: removed the `AiUsageFeatureEnum` describe block, its import and its `covers(...)` entry.

TASK-6: Added `database/migrations/2026_10_14_000002_drop_ai_usage_table.php`. `up()` runs `Schema::dropIfExists('ai_usage')`. `down()` recreates the table exactly as `2025_10_26_025059_create_ai_usage_table.php` did (empty; the data can't be recovered). Added the migration to `MIGRATION_COMPATIBILITY_ALLOWLIST` in `tests/Unit/MigrationCompatibilityTest.php` with the reason "`remove-ai-usage`: no supported client reads `ai_usage`". **I did not run `php artisan migrate`.** The owner should review the migration before deploying. It has only run against in-memory SQLite in tests.

TASK-7: Grep for `AiUsage|ai_usage|AiUsageFeatureEnum|assistant_chat|ai_tokens|saveUsageForUser`. The only code hits are the old create migration, the new drop migration, the allowlist entry and `tests/Fixtures/client-contract/1.0.15-migrations.txt`. Other hits are the unrelated older `ai_tokens` table migrations (2025_09_05 / 2025_09_08) and docs/backlog/reports/archived job files, which I left as they are. `composer test`: exit 0 (837 tests, 3043 assertions). `composer analyse`: exit 0, no errors.

## Known issues / follow-ups

- The frontend job to stop reading `statistics.overview.ai_tokens` (owner is creating it).
- `docs/backlog/remove-ai-usage.md` (and `.claude/backlog/remove-ai-usage.md`) is still open. Whether to delete it or mark it done is for the owner or pipeline to decide.
- Test names `respond returns the chat message content and tracks usage` and `new() calls the AI service with favorites and saves usage` still mention usage tracking. I didn't rename them (out of scope).
- Worktree environment: `vendor/` and `storage/framework/*` were missing, so I ran `composer install` from the lock file (no new packages) and created the gitignored cache directories. `.env.testing` is masked by the sandbox as a character device, which is why git shows it as modified and why every test reports a `file_get_contents(.env)` warning. I didn't commit it. On the very first test run, 2 `ExportTest` tests failed because `storage/app/public/user/` didn't exist yet. They passed on every later run, so this is an environment issue, not caused by this job.
