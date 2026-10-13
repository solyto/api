# Tasks: deployment of current main

id: hide
analyst: @analyst
date: 2026-10-13

## Context / scope notes

- This is a **report** job. No production code changes. The only deliverable is
  a written report at `docs/reports/deployment-readiness-1.0.15-to-main.md`, written
  using the `report` skill (`~/.claude/skills/report/SKILL.md`). `docs/reports/` does
  not exist in this repo yet, so the developer creates it with this first report.
- Range to analyse: `6bdedcd` (tag 1.0.15) .. `1bee6fa` ("full user deletion routine",
  the tip of `main` when this job branch was created).
  - **Do not** use the job branch HEAD as the end of the range: the job branch has
    two extra commits (`b27098a` scaffold, `99944fa` "[hide] chore: commit all")
    that are not part of `main`. Diff against `main` / `1bee6fa`.
- The reflog only shows some commits in the range. Two merge commits (`fa86708`,
  `d72587a`) pulled in commits from the remote that are not visible in the local
  reflog (probably the calendar marks / journal work). The developer must run
  `git log --oneline 6bdedcd..main` (incl. merges, `--first-parent` and full) to get
  the real list. Known from the reflog so far:
  - `e0f4e1f` deactivate movie release notifications
  - `4648dd9` notebook: journal folders
  - `fa86708` merge (remote commits — unknown content)
  - `acb653b` php/laravel/api best practices
  - `d72587a` merge (remote commits — unknown content)
  - `0ba7665` STANDARDS
  - `1bee6fa` full user deletion routine  ← must go live
- Candidate migrations added after 1.0.15 (verify with `git diff --name-status 6bdedcd..main -- database/`):
  `2026_10_10_000000_add_system_and_hidden_to_note_categories.php`,
  `2026_10_10_000001_backfill_journal_categories.php`,
  `2026_10_10_000001_add_color_to_time_tracking_projects.php`,
  `2026_10_10_000002_add_journal_display_settings_to_user_settings.php`.
- Calendar-mark related routes seen in `routes/api.php`: settings
  `journal-calendar-mark`, `check-in-calendar-mark`, `GET journal/marks`, `GET check-in .../marks`.
- The user-deletion work touches `app/Dav/Services/*` (the `pgsql` SabreDAV connection) —
  per project hard rules this must be **flagged** in the report.
- The frontend repo is not in this workspace. "Frontend compatibility" can only be
  judged from the API contract side (removed/renamed endpoints, changed request
  validation, changed response shapes). The report must state this limitation.

## Task breakdown

TASK-1: Produce the authoritative commit list for `6bdedcd..main` (including commits brought in by the merges `fa86708` and `d72587a`) and group every commit by feature (calendar marks, journal folders/categories, time-tracking colors, movie release notifications, standards/refactor, user deletion, other).
  files: none changed — read-only `git log`, `git show --stat`
  depends: none
  risk: low — pure information gathering; only risk is missing merged-in commits if `--first-parent` is used alone.

TASK-2: Analyse every migration added or changed in the range: additive vs. destructive, defaults on new columns, and whether data backfills (e.g. `backfill_journal_categories`, `system`/`hidden` note categories) change what an unchanged 1.0.15 frontend would see.
  files: `database/migrations/2026_10_10_*.php` (and any others reported by `git diff --name-status 6bdedcd..main -- database/`)
  depends: TASK-1
  risk: medium — a backfill or non-null column could silently change existing users' data (e.g. new system categories appearing in the old UI) even though no endpoint "breaks".

TASK-3: Compare the API contract between 1.0.15 and main — `routes/api.php`, Form Requests, API Resources, and the OpenAPI annotations — and list each removed/renamed endpoint, newly required request field, tightened validation rule, and removed/renamed/re-typed response field.
  files: `routes/api.php`, `app/Api/*/Requests/*`, `app/Api/*/Resources/*`, `app/Api/*/Controllers/*`, `app/OpenApi/OpenApiSpec.php`, `bootstrap/app.php` (error envelope)
  depends: TASK-1
  risk: high — this is where real breaking changes for the old frontend would hide; the "php/laravel/api best practices" and "STANDARDS" commits may have changed response shapes or status codes in passing.

TASK-4: Analyse the user-deletion commit `1bee6fa` in isolation: what it touches (incl. `app/Dav/Services/*` on the `pgsql` connection, `app/Api/Users/Commands/DeleteUser.php`, `UserService`, `UserPolicy`, library/attachment cleanup), whether it depends on any other post-1.0.15 commit (models, migrations, columns), whether it needs a frontend change to be usable, and whether it cherry-picks cleanly onto `6bdedcd`.
  files: `app/Api/Users/Services/UserService.php`, `app/Api/Users/Commands/DeleteUser.php`, `app/Api/Users/Policies/UserPolicy.php`, `app/Api/Users/Tests/DeleteUserTest.php`, `app/Dav/Services/{DavService,Calendars,AddressBooks}.php`, `app/Api/Libraries/Services/{LibraryCoverService,LibraryPlantService}.php`, `app/Api/Calendars/Services/EventAttachmentService.php`, `app/Api/Calendars/Controllers/CalendarController.php`
  depends: TASK-1
  risk: high — it deletes data across both databases (MariaDB + DAV `pgsql`); if it relies on tables/columns introduced by the unreleased features, a cherry-pick could fail or delete incompletely. Verifying a cherry-pick should be done in a throwaway worktree/branch only, never on `main`.

TASK-5: List non-API runtime changes in the range that affect a deploy independently of the frontend: scheduled jobs (`routes/console.php`), queued jobs, notifications (movie release notifications deactivated), config/cache stores, new Docker secrets via `DockerSecretHelper`, composer dependency changes.
  files: `routes/console.php`, `config/*.php`, `composer.json`/`composer.lock`, `app/Api/*/Jobs/*`, `app/Api/*/Notifications/*`
  depends: TASK-1
  risk: low — read-only check, but a new required secret or config key would make a deploy fail.

TASK-6: Check whether the unreleased features (calendar marks, journal folders/display settings, time-tracking colors, etc.) are "dark" when deployed — i.e. only reachable via new endpoints/settings the old frontend never calls — or whether they alter existing endpoints' behaviour/output.
  files: same as TASK-3 for the feature areas identified in TASK-1 (e.g. `app/Api/Notes/*`, `app/Api/CheckIn/*`, `app/Api/Users/Controllers/UserSettingsController.php`, `app/Api/TimeTracking/*`)
  depends: TASK-2, TASK-3
  risk: medium — the brief says these features are "not quite ready to be published"; being additive in the API does not guarantee they're invisible (e.g. new hidden/system categories showing up in existing list endpoints).

TASK-7: Write the report with the `report` skill and its structure (`# Report: <title>`, a `date:` / `scope:` header, then `## Summary`, `## Findings`, `## Recommendations`), filling the sections like this:
  - **Summary:** the verdict comes first. Can `main`'s API be deployed without the frontend, and how does `1bee6fa` reach production?
  - **Findings** (facts with evidence only, i.e. commit hashes, file paths, `git` commands): commit inventory grouped by feature, breaking-change table (endpoint/field, change, impact on the 1.0.15 frontend), migration assessment, non-API runtime changes (TASK-5), whether the unreleased features stay dark (TASK-6), a clear DAV/`pgsql` flag for the user-deletion change, and the limitation that the frontend repo was not available.
  - **Recommendations** (opinions, kept apart from the findings): the deploy options, (a) deploy `main` API-only as-is or (b) a hotfix release from `6bdedcd` plus a cherry-pick of `1bee6fa` (and any prerequisites), each named as a concrete action, then a recommendation and the open questions for the human (listed below).
  files: `docs/reports/deployment-readiness-1.0.15-to-main.md` (new, which also creates `docs/reports/`), `docs/jobs/hide_deployment-of-current-main/implementation.md` (short summary plus a link to the report)
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5, TASK-6
  risk: low — documentation only. The developer must not run migrations, deploy, tag, or push anything.

## Unclear / open questions (for the human, to be repeated in the report)

- "Calendar marks and a bunch of other stuff" — the exact list of features that must stay unpublished isn't given. TASK-1 will propose a grouping, but the human should confirm which ones count as "not ready".
- Whether "not ready to be published" just means "frontend shouldn't show it" (deploying additive API code is then fine) or "code/migrations must not reach production at all" (only option (b) works). The report should present both and not pick silently.
- The frontend repo isn't available here, so the compatibility judgement is based only on the API contract.
