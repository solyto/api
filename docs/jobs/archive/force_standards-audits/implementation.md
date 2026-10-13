# Implementation: Standards: audits

id: force
developer: @developer
date: 2026-10-13

## Summary

Wrote one read-only report, `docs/reports/standards-audits-2026-10-13.md`. It covers the five "Audits" items from `docs/STANDARDS_BACKLOG.md`. Each finding gives file:line, the rule, a verdict (OK / violation / owner decision) and a recommendation. Nothing in `app/`, `routes/`, `config/`, tests, `STANDARDS.md` or `STANDARDS_BACKLOG.md` was changed.

Main results:

- **A1 (rule 3.1):** 21 sites, not 22. The `TimeTrackingEntryController` case was already fixed in `ca1627b`. The backlog missed `NotificationSettingsController::update`, which validates 36 fields inline. Two `update` actions with ≤ 3 fields (one is in the Todos reference module) need an owner ruling.
- **A2 (rule 9.2):** recommended numbers for the six jobs, all below Redis `retry_after` = 360. The import jobs' in-code 1800 s budget conflicts with `retry_after`, so that one goes to the owner.
- **A3 (OQ-7):** the open question's premise is wrong. `ShareCalendarRequest::after()` has restricted sharing to friends since the initial commit. Recommend closing OQ-7 and adding tests.
- **A4:** method lengths re-measured and unchanged. All six are "keep".
- **A5 (rule 1.4):** 11 group names + 1 route name, not seven. There are no callers, but the group names keep the `apiResource('/')` route names unique. Recommend keeping them.

## Changes

TASK-1: created `docs/reports/standards-audits-2026-10-13.md` with header, scope, verdict legend and one section per audit item.
TASK-2: section A1. Covers all 21 inline `validate()` sites with route verb, field count, update status and duplicate shapes, plus the drift notes.
TASK-3: section A2. Lists all 18 jobs with their current values. For the six named jobs it describes the work, retry-safety and recommended `$tries`/`$timeout`, checked against `config/queue.php:54` `retry_after`. Includes the import-timeout owner decision and one line each for the other eight. DAV `pgsql` writes are flagged.
TASK-4: section A3. Documents OQ-7: request `rules()` + `after()`, the controller flow down to the DAV sharing backend (flagged `pgsql`), what `Friend` offers, the remaining owner question and the missing tests.
TASK-5: section A4. Re-measured with a tokenizer script kept outside the repo. Gives callers per method, verdicts, the next-in-line list and the `pgsql` check note.
TASK-6: section A5. Inventories all route names, runs the caller grep over app/tests/views, explains the apiResource-name collision, and lays out keep vs drop with a verification command.
TASK-7: summary table, method/limits section and 14 numbered backlog/standards corrections for the follow-up job.

## Known issues / follow-ups

- `vendor/` is not installed in this worktree, so nothing was run through artisan. The A5 claim that dropping the names creates duplicate names that break `route:cache` comes from reading framework source. The report gives `php artisan route:list --json | jq … | uniq -d` to confirm it before acting.
- Worker `--tries`/`--timeout` flags and whether production runs `route:cache` live in the separate deployment repo and could not be checked.
- `docs/STANDARDS_BACKLOG.md` "Rewrites" items done in `ca1627b` appear unticked (two are confirmed in the report). The rest of that section was not audited.
- `.env.testing` shows as modified in `git status`, but it is a character device mounted by the session (`/dev/null`-style), not a real change. It was deliberately not staged.
