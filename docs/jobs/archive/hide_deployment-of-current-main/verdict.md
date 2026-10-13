# Verdict: deployment of current main

id: hide
reviewer: @reviewer
date: 2026-10-13

## Review

Diff `main...HEAD` touches only docs: the new report
`docs/reports/deployment-readiness-1.0.15-to-main.md` plus the job files. No
production code, migrations or config changed, which is right for a report job.

TASK-1: PASS
notes: The commit inventory matches `git log --oneline 6bdedcd..main` (10 commits).
It includes `588773d`, `780d58d` and `c48fa49`, which came in through the merges and
are missing from `--first-parent`.

TASK-2: PASS
notes: All 4 migrations match `git diff --name-status 6bdedcd 1bee6fa -- database/`.
I checked the backfill (`chunkById(500)` → `NoteCategory::provisionJournal`,
`firstOrCreate` with `is_system`/`is_hidden` true, no-op `down()`). The report
describes it correctly.

TASK-3: PASS
notes: `routes/api.php` only gains lines (6 new routes, same as the report). The
Requests and Resources diffs only add things: `color` is optional, and the new
fields and new request classes only affect new endpoints. I confirmed the
behaviour changes the report lists: `listRoots` has no hidden/system filter, the
`abort_if($category->is_system, 403)` checks in update/destroy, and the stats
colour switching from `categories->first()?->color` to `project->color`.

TASK-4: PASS
notes: I re-checked all 14 files touched by `1bee6fa` with `git rev-parse
6bdedcd:<f>` vs `1bee6fa^:<f>`, and all are identical. `routes/web.php`,
`bootstrap/`, `config/` and `app/Shared/` are unchanged. The report clearly
flags the DAV/`pgsql` DELETEs and states that the schema and routes are not
changed.

TASK-5: PASS
notes: `routes/console.php` only comments out `GrabMovieReleases`. No
Jobs/Notifications/config/composer files changed in the range.

TASK-6: PASS
notes: The dark/not-dark table follows from the verified F3 findings.

TASK-7: PASS
notes: The report follows the required structure (Summary → Findings →
Recommendations), keeps facts and opinions separate, presents options A and B,
and repeats the open questions and the frontend-repo limitation. The developer
says plainly that the cherry-pick and test run were not actually executed.

## Security

None run. Documentation-only change, and no secrets appear in the report.

## Overall

APPROVED

No blockers.
