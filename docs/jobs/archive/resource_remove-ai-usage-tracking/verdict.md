# Verdict: Remove AI usage tracking

id: resource
reviewer: @reviewer
date: 2026-10-14

## Review

Branch: `feature/resource_remove-ai-usage-tracking`, matching `brief.md`. Base: `main` (from `.manigot/manigot.json`).
Review surface: `git diff main...HEAD`. Every source change in the diff is listed in `implementation.md`, and nothing changed outside the tasks. The four `chore: commit all` commits touch only job files (`brief.md`, `tasks.md`, `tokens.json`).

TASK-1: PASS
notes: The gate check was run, and the developer correctly stopped. The developer session log ends at about 21:24 with
`NEEDS-HUMAN-INPUT: The 1.0.15 frontend reads statistics.overview.ai_tokens …` (`src/lib/types/statistic.ts:31`,
`src/routes/admin/+page.svelte:154` with a `?? 0` fallback). The code commits come later (21:30–21:35), and
`implementation.md` records the owner's decision to remove `ai_tokens` outright, with a separate frontend job as follow-up.
That owner reply isn't in `session.log`; it presumably happened in a session outside the log. I take the recorded
decision at face value. If it's wrong, TASK-4 must be reverted. Impact if removed: on the admin-only page, 1.0.15
admins see a 0 tile because of the `?? 0` fallback, so nothing breaks.

TASK-2: PASS
notes: `LibraryRecommender.php` drops the call and the `AiUsageFeatureEnum` import, and `new()` returns the
`respondStructured()` result directly. `LibraryServiceTest` drops the `shouldReceive('saveUsageForUser')` expectation.
The `$user` parameter of `new()` is still used for the favorites, so it isn't dead.

TASK-3: PASS
notes: `AiService.php` drops `saveUsageForUser()`, the three token properties, the lines that fill them in both
methods, and the `User`/`AiUsageFeatureEnum`/`AiUsage` imports. Removing both `$this->model = $response->model`
assignments is correct: nothing read `$this->model` after the assignment except a later request on the same instance,
which would have silently changed the configured model. `private string $model` stays, because the constructor and the
request calls use it. The request logic is otherwise unchanged. The test and imports in `LibraryServiceTest` are removed.

TASK-4: PASS
notes: `StatisticsService.php` drops the `ai_tokens` entry and the `AiUsage` import. The OpenAPI schema
(`StatisticsResource`) is `AdditionalProperties` only, so it needed no change.

TASK-5: PASS
notes: Both files are deleted. `EnumsTest.php` drops the describe block, the import and the `covers(...)` entry.

TASK-6: PASS
notes: `2026_10_14_000002_drop_ai_usage_table.php`: `up()` is `Schema::dropIfExists('ai_usage')`, and `down()`
matches `2025_10_26_025059_create_ai_usage_table.php` column for column, including the cascade FK. The allowlist entry
has the reason exactly as the brief gives it. It doesn't touch the `pgsql` connection. Not run against a real DB, as
required. The owner still has to review it before `php artisan migrate`, because it drops data for good.

TASK-7: PASS
notes: My own grep of `app tests database config routes bootstrap resources` for
`AiUsage|ai_usage|AiUsageFeatureEnum|assistant_chat|ai_tokens|saveUsageForUser` finds only the old create migration,
the new drop migration, the allowlist entry, `tests/Fixtures/client-contract/1.0.15-migrations.txt`, and the unrelated
2025_09 `ai_tokens` table migrations. The other hits are in docs, backlog and archived jobs.
I ran the full suite in the worktree:
- `composer test`: exit 0, 837 tests, 3043 assertions. Every test shows the sandbox-only `file_get_contents(.env)`
  warning, which comes from the masked `.env.testing` and has nothing to do with this job.
- `composer analyse`: exit 0, `[OK] No errors`.

## Security

Not run. The change only removes code and storage, and adds no new inputs or endpoints.

## Overall

APPROVED

No blockers.
