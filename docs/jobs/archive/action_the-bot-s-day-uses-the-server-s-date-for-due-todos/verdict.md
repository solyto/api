# Verdict: the bot's /day uses the server's date for due todos

id: action
reviewer: @reviewer
date: 2026-11-22

## Review

TASK-1: PASS
notes: `app/Bots/SolytoBot.php:98-99` resolves `$timezone` once from `$this->user->settings->timezone ?? 'UTC'` and passes `today($timezone)->toDateString()` to `TodoService::due()`. The appointment formatting reuses `$timezone`, and the old `$this->connection->user` lookup is removed. `$this->user` is set in `auth()`, which runs before this code.

TASK-2: PASS
notes: `app/Bots/Tests/SolytoBotTest.php:291-315` adds two cases as specified: Berlin at 23:30 UTC with chat 605, and New York at 02:00 UTC with chat 606, which asserts both the listed and the excluded todo. Both cases would fail against the UTC `today()`. `setTestNow` is reset in the existing `afterEach`. The FQN `\Carbon\Carbon` matches the existing usage in the file.

TASK-3: PASS
notes: `TodoService::due()` now always calls `orderBy('due_at')` and applies the limit only when one is given. The docblock is updated. The optional tests were added: an unlimited-order test in `TodoServiceTest`, and an Overdue-before-Due-today assertion in the existing `/day` test. The widget behaviour is unchanged.

Scope: the diff contains only the files listed in tasks.md. No user-facing strings changed. The STANDARDS.md and TESTING.md rules are met; the time-dependent tests freeze time with `Carbon::setTestNow()`.

Checks run by the reviewer in the worktree: `composer test` exit 0 (all assertions pass; the warnings come from the sandbox masking `.env.testing` as a device and are not related to this job), `composer analyse` exit 0 (no errors).

## Security

none

## Overall

APPROVED

No blockers.
