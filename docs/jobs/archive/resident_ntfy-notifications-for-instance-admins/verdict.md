# Verdict: ntfy notifications for instance admins

id: resident
reviewer: @reviewer
date: 2026-10-14

Branch verified: `feature/resident_ntfy-notifications-for-instance-admins`. Reviewed `git diff main...HEAD`. The diff matches implementation.md, and there are no unlisted changes outside `docs/jobs/` (the uncommitted `.env.testing` entry in `git status` is the sandbox mount, `/dev/null`, and is not part of the branch).

Verification run in the worktree:
- `composer test`: exit 0, all 777 tests pass. Every test shows a "warning", but that comes from the missing `/workspace/.env` in the sandbox and isn't caused by this job.
- `composer analyse`: exit 0, no errors.

## Review

TASK-1: PASS
notes: The `config/services.php` `ntfy` block has `url` from `env()` and `topic`/`token` from `DockerSecretHelper`, with no defaults and no ntfy.sh fallback. Because of `?string` return coercion, `DockerSecretHelper::get()` turns a missing var under `APP_DEBUG=true` into `""`, so `filled()` handles it correctly.

TASK-2: PASS
notes: `NtfyMessage::toArray()` returns exactly `topic`, `title` and `message`, with no extra fields.

TASK-3: PASS
notes: `NtfyChannel` sends a JSON POST to the server root with a 5 s timeout, and calls `withToken()` only when the token is `filled()`. It catches `ConnectionException`. On failure it logs only status and host, never the token, headers or request.

TASK-4: PASS
notes: `AdminNotification::via()` returns `[]` unless both URL and topic are `filled()`. `BaseNotification` is untouched.

TASK-5: PASS
notes: Both notifications are `ShouldQueue` with `$tries = 1` / `$timeout = 30`, and a reason comment that is consistent with STANDARDS 9.2 (30 s is below `retry_after` 360 s). They carry scalars only and have no PII. The brief left the title/message split open; it is documented in implementation.md ("New dev request: <title>" is the message body).

TASK-6: PASS
notes: The on-demand call runs after the verification mail and after the rollback branch.

TASK-7: PASS
notes: The on-demand call runs after the screenshot block and uses `$devRequest->title`.

TASK-8: PASS
notes: Tests cover off (null, empty string, and a real empty secret file), on (exact JSON body to the root URL), the bearer token present or absent (null and `""`), and a 500 or `ConnectionException` not throwing, with the token absent from logs.

TASK-9: PASS
notes: Register and dev request create are each tested off (nothing sent), on (exactly one request with no email, name or description) and failing (still 201, row exists).

TASK-10: PASS
notes: The Decisions entry is narrow and limited to `AdminNotification` subclasses. A one-line cross-reference was added in 9.4, which the task allowed.

TASK-11: PASS (not done, as planned)
notes: The repo has no `.env.example`. implementation.md records it as a cross-repo follow-up.

## Security

Not run separately. The reviewer saw no token leak in logs, no user PII in payloads and no SSRF surface (operator-configured URL only).

## Overall

APPROVED. No blockers.
