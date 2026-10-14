# Verdict: Remove dead service config

id: size
reviewer: Claude (reviewer agent)
date: 2026-10-14

## Review

Branch verified: `feature/size_remove-dead-service-config`. Base: `main` (from `.manigot/manigot.json`).
Reviewed `git diff main...HEAD`. The only non-job-doc change is `config/services.php` (-8 lines).

TASK-1: PASS
notes: The `bremerstadtreinigung` block and the blank line after it are removed from `config/services.php`. The blocks on either side (`...]` close / `hardcover`) are intact, and the commas and brackets are correct. `php -l` is clean.

TASK-2: PASS
notes: The `radicale` block (`env('RADICALE_URL')`) and the blank line after it are removed. `ai` and `mailgun` around it are untouched. Nothing on the `pgsql` connection or the DAV routes was touched.

TASK-3: PASS
notes: I re-ran both checks myself in the worktree:
- `composer analyse`: exit 0, "[OK] No errors".
- `composer test`: exit 0, 839 tests, 3052 assertions, no failures.

  Every test is reported as a warning: `file_get_contents(/workspace/.env): Failed to open stream`. This comes from the review sandbox: there is no `.env`, and `.env.testing` is mounted as `/dev/null`. It is not caused by this diff.

Pre-check from the brief: I repeated the grep for `bremerstadtreinigung`, `trash_calendar_url`, `radicale` and `RADICALE_URL`, excluding `vendor/` and `node_modules/`. The only hits outside `config/services.php` are docs: the backlog entry and the job files under `docs/` and `.claude/`. No code, env, or resource file references them.

Scope: nothing changed outside the tasks. Other `services.php` entries and the `ai_usage` code are untouched. The `.env.testing` entry in `git status` is the sandbox's `/dev/null` mount, not a committed change.

## Security

None. The change only deletes config and adds no secrets.

## Overall

APPROVED. Nothing has to change before merge.
