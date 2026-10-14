# Verdict: Remove the unused per-user OpenAI key

id: quite
reviewer: reviewer
date: 2026-10-14

## Review

Reviewed against `git diff main...HEAD` (base from `.manigot/manigot.json`).
Every non-doc change maps to a task; nothing outside tasks.md changed.

TASK-1: PASS
notes: `1.0.15-routes.json` only loses the `PUT api/v1/users/me/settings/openai-api-key`
object (JSON still parses, the suite loads it). `ClientContractTest.php:177` only drops
`'openai_api_key'` from the settings list. No other pins touched, as tasks.md requires.

TASK-2: PASS
notes: Route removed from `routes/api.php`. Controller action, its `@OA\Put` block and
the `UpdateOpenaiApiKey` import removed. `UpdateOpenaiApiKey.php` deleted.

TASK-3: PASS
notes: `UserSettingsService::updateOpenaiApiKey()` removed. No remaining callers.

TASK-4: PASS
notes: Both `toArray()` entries and both `@OA\Property` lines removed. Only the two
matching baseline entries were deleted, with no baseline regeneration.

TASK-5: PASS
notes: Fillable entries, the `ai_enabled` cast, the factory defaults and `withAiEnabled()`
all removed.

TASK-6: PASS
notes: All three named tests deleted, and nothing else in those files changed.

TASK-7: PASS
notes: New migration `2026_10_14_000002_...` is ordered after the latest migration
(`2026_10_14_000001`). It uses the default connection, with no `pgsql` involvement.
`up()` drops both columns. `down()` re-adds `ai_enabled` boolean default false and
`openai_api_key` nullable string. The allowlist entry has the exact reason from the
brief. The developer says it ran only in the in-memory SQLite suite. The owner still
needs to see it before it runs against a real DB (hard rule), and that step is
outside this review.

TASK-8: PASS
notes: Re-ran the grep outside docs/.claude/vendor/storage. The only hits are the two old
migrations, the new migration, its allowlist entry and `1.0.15-migrations.txt`. I ran the
full suite myself: `composer test` exited 0 (3042 assertions; the 836
`file_get_contents` warnings show up in every module and are environmental, not caused
by this job). `composer analyse` exited 0 ("No errors").

## Security

Not run separately. This change removes a plain-text secret from the API response
and from storage, which is a net improvement.

## Overall

APPROVED

No blockers. Before deploy, the owner must review the drop migration (CLAUDE.md
hard rule). It is destructive by design.
