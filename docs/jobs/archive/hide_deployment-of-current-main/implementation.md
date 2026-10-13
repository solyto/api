# Implementation: deployment of current main

id: hide
developer: @developer
date: 2026-10-13

## Summary

This was a report job, so no production code changed. The deliverable is
[`docs/reports/deployment-readiness-1.0.15-to-main.md`](../../reports/deployment-readiness-1.0.15-to-main.md),
which analyses `6bdedcd` (1.0.15) .. `1bee6fa` (`main`).

Verdict: no endpoint, request rule or response field was removed or tightened,
and all four migrations are additive. However, two changes are visible to a
1.0.15 frontend:
- the `.journal` category backfill shows up in `GET /v1/notes/categories`;
- time-tracking statistics colours go `null`.

`1bee6fa` (user deletion) has no dependency on any other commit since 1.0.15,
and every file it touches is identical at `6bdedcd`. It is CLI-only and needs
no frontend change. It does write to the DAV `pgsql` connection (flagged).
The report recommends a hotfix: 1.0.15 + cherry-pick `1bee6fa`.

## Changes

TASK-1: Built the commit inventory with full log, `--first-parent` and the
merge parents. 10 commits, of which 2 are merges without conflict resolutions
and 2 are docs-only. Grouped by feature in report F1.
TASK-2: Assessed the 4 migrations, including the irreversible `.journal`
backfill (report F2).
TASK-3: Compared the API contract: 6 new endpoints and additive fields, no
removals. Two behaviour changes are visible to the old UI (report F3).
TASK-4: Analysed `1bee6fa` on its own: dependencies, a per-file clean
cherry-pick check, no HTTP surface, and the DAV/`pgsql` flag (report F4).
TASK-5: Non-API runtime changes. The only one is the `GrabMovieReleases`
schedule being disabled; config, composer and secrets are unchanged
(report F5).
TASK-6: Checked which unfinished features stay dark (report F6).
TASK-7: Wrote the report (new `docs/reports/`) and this summary.

Files: `docs/reports/deployment-readiness-1.0.15-to-main.md` (new),
`docs/jobs/hide_deployment-of-current-main/implementation.md`.

## Known issues / follow-ups

- The test suite was not run: there is no `vendor/` in the worktree. The clean
  cherry-pick of `1bee6fa` is shown by per-file blob identity, not by an
  actual cherry-pick plus `composer test`, because the session git shim blocks
  `cherry-pick` and `merge-tree`. Do both on the hotfix branch.
- The frontend repo was not available, so the UI impact of F3 #1/#2 is
  inferred from the API only.
- If Option A is ever wanted: `GET /v1/notes/categories` should hide system
  or hidden categories, and stats colours need a backfill or a fallback.
  Each would be a separate job.
- `/workspace/.env.testing` shows as modified in `git status`, but it is a
  character device mounted by the sandbox. It was not touched and cannot be
  committed.
