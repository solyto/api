# Tasks: keep client errors and stack traces out of dav.log

id: twice
analyst: analyst
date: 2026-10-29

## Task breakdown

TASK-1: In `config/logging.php`, change the `dav` channel to `'driver' => 'daily'`, give it its own level `env('DAV_LOG_LEVEL', 'warning')`, and add a `days` retention (reuse `env('LOG_DAILY_DAYS', 14)` like the existing `daily` channel); keep `path` and `replace_placeholders` unchanged.
     files: config/logging.php
     depends: none
     risk: low — config-only change. All app-side `Log::channel('dav')` calls are `warning`/`error` (CalendarImport, ContactImport, CacheInvalidationPlugin, public/dav.php), so they stay visible. Only Sabre's INFO-level 4xx traces from `DavController::setLogger` are dropped.

## Out of scope

- Brief step 2 (reject `/.git`, `.env`, `wp-*`, `*.tfvars` at the reverse proxy) belongs to the separate solyto/deployment repo. Not part of this job.
- No change to the `pgsql` connection, DAV routes or DAV code.

## Notes for developer / reviewer

- Check (from brief): `grep -n "'dav'" -A5 config/logging.php` shows the `daily` driver, a `DAV_LOG_LEVEL` level defaulting to `warning`, and `days`.
- With the `daily` driver, Monolog writes `storage/logs/dav-YYYY-MM-DD.log` instead of `dav.log`. Nothing in this repo reads `dav.log` by path, but any external tail, log shipping or deployment-repo tooling that does will need updating. Mention this in implementation.md as a follow-up; it can't be checked from here.
- There is no `.env.example` in the repo, so there is nowhere to document `DAV_LOG_LEVEL` besides the config default. Do not edit `.env.testing`.
- `app/Dav/Tests/DavImportTest.php` mocks `Log::channel('dav')`, so it is unaffected. No new test is needed for a config value.
- Backlog entry `docs/backlog/dav-log-noise.md` is the source of this job. Whether to remove or close it once the job is done follows the project's backlog workflow, not this task list.
- Branch: the worktree's `.git` points at `worktrees/twice_keep-client-errors-and-stack-traces-out-of-dav-log`, which matches `branch:` in brief.md. The analyst has no shell, so `git branch --show-current` was not run.
- Done = `composer test` and `composer analyse` both exit 0.
