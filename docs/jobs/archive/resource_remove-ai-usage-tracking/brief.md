# Brief: Remove AI usage tracking

type: feature
id: resource
branch: feature/resource_remove-ai-usage-tracking
date: 2026-10-14
author: Leander Muskalla

## What
Remove the `ai_usage` setup completely:

1. **Model and enum:** delete `app/Shared/Models/AiUsage.php` and `app/Shared/Enums/AiUsageFeatureEnum.php` (including `ASSISTANT_CHAT` and `LIBRARY_RECOMMENDER`).
2. **`AiService`** (`app/Api/Libraries/Services/AiService.php`): delete `saveUsageForUser()` (`:86`) and the `$inputTokens` / `$outputTokens` / `$totalTokens` properties and the lines that fill them in `respond()` and `respondStructured()`. Remove `$this->model = $response->model` if nothing else reads it after that.
3. **`LibraryRecommender`** (`app/Api/Libraries/Services/LibraryRecommender.php:127`): drop the `saveUsageForUser` call and its import.
4. **Statistics:** drop `'ai_tokens' => AiUsage::sum('total_tokens')` and the import from `app/Api/Statistics/Services/StatisticsService.php:27,70`, plus `ai_tokens` from the overview's `@OA` schema, if it is listed there (rule 2.6).
5. **Migration:** one new migration that drops the `ai_usage` table (`Schema::dropIfExists`). `down()` recreates it in the shape of `2025_10_26_025059_create_ai_usage_table.php`. Add it to `MIGRATION_COMPATIBILITY_ALLOWLIST` in `tests/Unit/MigrationCompatibilityTest.php` with the reason "`remove-ai-usage`: no supported client reads `ai_usage`".
6. **Tests:**
   - `app/Api/Libraries/Tests/LibraryServiceTest.php`: delete the `saveUsageForUser persists the tracked usage` test (`:73`) and the `shouldReceive('saveUsageForUser')` expectation (`:153`), plus the imports (`:9-10`).
   - `tests/Unit/EnumsTest.php`: delete the `AiUsageFeatureEnum` block (`:31-36`) and remove the enum from the import and `covers(...)` (`:5,8`).

## Why
AI becomes a plugin where each user brings their own key (`ai-plugin`). Usage tracking was there to control the cost of the instance's own key. Without that key there's nothing to control, and storing per-user token usage nobody looks at doesn't fit a privacy-focused app. The owner decided on 2026-10-14 that `ai_usage` goes away completely.

## Out of scope
- The rest of the AI plugin work (`ai-plugin`, `user-plugins-storage`).
- `AiService`'s request logic (`respond`, `respondStructured`) apart from the usage lines.

## Notes
- `GET statistics/overview` is admin-only (`StatisticsController.php:43`, `isAdmin`), so removing `ai_tokens` should affect no user-facing client. Before removing it, grep the 1.0.15 frontend (`solyto/app` at tag `1.0.15`, read-only) for `ai_tokens`. If it reads it, stop and report (rule 13.4).
- Show the migration to the owner before running it (CLAUDE.md hard rule).
- Afterwards, grep for `AiUsage`, `ai_usage`, `AiUsageFeatureEnum`, `assistant_chat` and `ai_tokens`. The only hits left should be the old migration, the new drop migration, the allowlist entry and `tests/Fixtures/client-contract/1.0.15-migrations.txt`.

Check: `composer test` and `composer analyse` exit 0.
