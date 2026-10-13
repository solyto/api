# Verdict: Standards: audits

id: force
reviewer: @reviewer
date: 2026-10-13

## Review

Branch `feature/force_standards-audits`, diffed against `main` (from `.manigot/manigot.json`).
`git diff main...HEAD --stat` touches only `docs/jobs/force_standards-audits/*` and the new
`docs/reports/standards-audits-2026-10-13.md`. No changes in `app/`, `routes/`, `config/`,
tests, `docs/STANDARDS.md` or `docs/STANDARDS_BACKLOG.md`, so the read-only scope from tasks.md
was respected. The `.env.testing` entry in `git status` is a session mount and is not committed.

Claims I spot-checked against the tree, all of which hold:
- 21 `$request->validate(` sites. There is no `TimeTrackingEntryController:158` hit; the hits are at `:260` / `:384`.
- `NotificationSettingsController::update` validates 36 fields inline (`:107-144`) on `PUT`.
- `TodoController::updateSubtask` (`:383`, `PUT todos/{todo}/subtasks/{subtask}`) and `PasskeyController::update` (`:91`, `PUT auth/passkeys/{passkey}`) are `update` actions.
- `config/queue.php:54` `retry_after` = 360. The existing job values are 60/240/300/300. Both import jobs loop with a 1800 s budget (`:32`).
- `ShareCalendarRequest::after()` (`:18-41`) rejects self and non-friends. It is the only `after()` hook in `app/Api/*/Requests`.
- 11 group `->name()` calls plus `->name('uploadCover')` at `routes/api.php:299`, at exactly the listed lines.
- `TodoService::parse` has 2 production callers (`TodoController:166`, `QuickAddService:144`) plus 8 test calls.
- The `pgsql` grep matches the three known files plus `DeleteUserTest.php`.
- `STANDARDS.md` still cites `TimeTrackingEntryController.php:158-165` / `:167-170`, so the listed corrections are valid.

TASK-1: PASS
notes: The report is at `docs/reports/standards-audits-2026-10-13.md` and uses the kebab-case naming. It has a header, scope, method/commands, a verdict legend, one section per audit item and a summary table.

TASK-2: PASS
notes: All 21 sites have route verb, field count, whether the action is an `update` and duplicate shapes. The TimeTracking case is re-located (fixed in `ca1627b`) and the drift is stated. A new 36-field violation was found. `update` is judged by both method name and route verb, as the risk note asked.

TASK-3: PASS
notes: All 18 jobs are listed with their current values and dispatch sites. Each of the six named jobs has its work, retry-safety and a recommendation, and every `$timeout` stays under the cited `retry_after` 360. The import-timeout conflict goes to the owner and the DAV `pgsql` writes are flagged. The other eight each get a one-line justification.

TASK-4: PASS
notes: The report covers `rules()` and `after()`, the controller flow down to the DAV sharing backend (`pgsql` flagged, nothing proposed for change), and what `Friend` / `User::friends()` offer. It finds that OQ-7's premise is wrong and leaves the remaining question to the owner. It also notes that sharing has no tests.

TASK-5: PASS
notes: The counting convention is stated. All six lengths are confirmed, with callers and per-method verdicts. The second caller of `TodoService::parse` is reported openly, with a reasoned "keep". The optional next-in-line list is included.

TASK-6: PASS
notes: The report lists every name, and the caller grep covers app, tests, resources, bootstrap, config, routes and database. It states the count drift and lays out keep vs drop as an owner decision. The `apiResource('/')` name-collision argument has not been run (no `vendor/`), and the report says so, giving a verification command.

TASK-7: PASS
notes: The summary table has the columns tasks.md asked for. There are 14 backlog/standards corrections for the follow-up, and neither file was edited.

## Security

Not run. The change is docs-only. The report contains no secrets.

## Overall

APPROVED

No blockers.
