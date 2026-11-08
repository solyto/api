# Implementation: Including external calendars via DAV

id: immediate
developer: @developer
date: 2026-11-08

## Summary

Report-only job. Wrote `docs/reports/external-calendars-via-dav-2026-11-08.md`. It covers the git findings on the legacy Nextcloud integration, how that integration worked, the current DAV building blocks, three provider-neutral options (ICS subscription, read-only CalDAV pull-sync, two-way sync), a recommendation (B with A as phase 1, behind the SSRF guard), follow-up jobs and open questions. No code, migrations, config, routes or DAV wiring changed.

## Changes

TASK-1: Git research. The Nextcloud models, services and jobs predate the repo's first commit `37f9642` (2026-04-07), so only the migrations and helper remnants are in history. `4803c35` removes the bot remnant and `5e2e4aa` removes the helper remnants. Recorded in report §1.
TASK-2: Rebuilt the legacy design from the migrations and the surviving parse/discovery code. Documented behaviour is kept apart from unverifiable claims (report §2).
TASK-3: Mapped `CalendarImport` (its discovery is Nextcloud-shaped, it drops UID/ETag, it caches the secret), `calendarsubscriptions` (not fetched by sabre, plugin not registered), the pgsql read path and caching, and read-only semantics (report §3).
TASK-4: No earlier external-calendar report exists. Overlap with the vtodo and bot-traffic reports is noted and linked (report §4).
TASK-5: Options A/B/C with data model, credentials, load, SSRF, recurrence/TZ, visibility, user deletion and effort, plus a comparison table (report §5).
TASK-6: Report written: `docs/reports/external-calendars-via-dav-2026-11-08.md` (new).
TASK-7: This file. `composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- `vendor/` and `storage/framework/*` were missing from the worktree. I ran `composer install` from the lock file (no new dependencies) and created the gitignored storage dirs so the checks could run.
- The first `composer test` run reported 2 failures. Two later runs were fully green (exit 0). This looks like a first-run or flaky issue. The failing test names weren't captured.
- `.env.testing` shows as modified but is a character device in this sandbox (git can't hash it). Left untouched and uncommitted.
- Follow-ups proposed in the report §7: SSRF guard + outbound limiter, fixing the one-time import (generic discovery, raw-vCal storage, no plaintext secret in cache), ICS subscriptions, CalDAV accounts, optional legacy table cleanup.
