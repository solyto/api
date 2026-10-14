# Verdict: AI runs on the user's plugin config

id: reading
reviewer: @reviewer
date: 2026-10-14

## Review

Branch checked: `feature/reading_ai-runs-on-the-user-s-plugin-config` (matches brief). Diff reviewed: `git diff main...HEAD`.
Verified in the worktree: `composer test` exits 0 (all tests pass, 3285 assertions). The 892 "warnings" all come from the missing `/workspace/.env` (phpdotenv `file_get_contents`), which is an environment issue, not caused by this job. `composer analyse` exits 0 (`[OK] No errors`). Resolving `AiService` from the container leaves both optional seams (`client`, `httpClient`) `null`, so production builds the real client from the plugin row.

TASK-1: PASS
notes: `AiService` moved to `app/Plugins/Ai/Services/AiService.php` (namespace `App\Plugins\Ai\Services`). The old file is deleted. Imports are updated in `LibraryRecommender` and `LibraryServiceTest`. Nothing else references the old namespace.

TASK-2: PASS
notes: The three `AiService` tests and the `fakeChatResponse()` / `mockedOpenAiClient()` helpers now live only in `app/Plugins/Ai/Tests/AiServiceTest.php`, so the helpers are not declared twice. The recommender and releases tests stay in Libraries.

TASK-3: PASS
notes: `UserPluginService::aiConfig()` reuses the existing private checks: row, `apiKeyStored` (including the decrypt-safe path), and `aiModelAvailable`. `AiService` takes `User` as a method argument, returns `null` before building any client, and reads no `config('services.ai.*')`. The `ClientContract` seam is kept and is only used after the availability check. The PSR-18 seam choice is documented in implementation.md, adds no packages, and adds no new abstraction. The API key is never logged.

TASK-4: PASS
notes: `LibraryRecommender::new()` passes `$user`, and a `null` result from AI goes through unchanged. The test now asserts the user and favorites arguments, and there is a new null-path test.

TASK-5: PASS
notes: `UserPluginService::update()` forgets `book_recommendations` / `music_recommendations` for the user on every AI save, using private const keys (follows the `CacheInvalidationPlugin` precedent). `update()` is the only write path. The Plugins → Libraries key coupling was flagged as requested. It is acceptable and not a blocker.

TASK-6: PASS
notes: The `ai` block is removed from `config/services.php`. The `services.ai.api_key` stub is removed from `tests/TestCase.php`, and the comment there now only covers the app key. The `app.key` line and the `solyto_ai` block are untouched.

TASK-7: PASS
notes: `docs/TESTING.md` has been updated. `grep services.ai` finds hits only in historical job records under `docs/jobs`/`.claude/jobs` (archive and this job's own brief/tasks), which is the reading the analyst flagged for owner confirmation.

TASK-8: PASS
notes: Every test the brief asks for in §6 is present:
- The provider URL, `Bearer` key and model are asserted on the real outgoing request (Guzzle history).
- Six unavailable cases (no row, disabled, null key, empty key, unreadable key, retired model) assert no `chat()` call and `null` from both methods.
- `NEW` for books and music returns the same 404 envelope as the no-favorites case.
- Cache invalidation is checked with an isolation check against another user.

Scope: no changes outside the task list. The uncommitted working-tree change to `.env.testing` is not part of the branch diff (implementation.md notes that file is mounted as `/dev/null`), so it is not a finding.

## Security

Not run separately. The reviewer found nothing: the key is never logged or put into exceptions, and decrypt failures are handled without throwing.

## Overall

APPROVED

No blockers. Reminders for the owner when this ships (already in implementation.md, and not code blockers):
- Remove the `AI_API_KEY` Docker secret and the `AI_BASE_URL` / `AI_MODEL` env from the solyto/deployment repo.
- Announce, outside the app, that AI recommendations through the instance key stop until users set up the AI plugin.
