# Verdict: keep client errors and stack traces out of dav.log

id: twice
reviewer: reviewer
date: 2026-10-29

## Review

TASK-1: PASS
notes: `config/logging.php:96-102` — `dav` channel now `driver: daily`,
`level: env('DAV_LOG_LEVEL', 'warning')`, `days: env('LOG_DAILY_DAYS', 14)`;
`path` and `replace_placeholders` unchanged, matching the existing `daily`
channel pattern. All app-side `Log::channel('dav')` calls are `warning`/`error`
(CalendarImport, ContactImport, CacheInvalidationPlugin, public/dav.php), so
they stay visible; only Sabre's INFO-level 4xx traces via
`DavController::setLogger` are dropped. Brief check
(`grep -n "'dav'" -A5 config/logging.php`) satisfied.

Scope: the diff against `main` touches only `config/logging.php` plus the job
docs. No DAV code, routes or `pgsql` connection changed. The working tree shows
`.env.testing` as modified, but it's a character-device mount from the
environment, not a developer change. It isn't committed.

Verification (run by reviewer, full suite): `composer test` exit 0 (no
failures; the warnings are pre-existing), `composer analyse` exit 0
(`[OK] No errors`).

## Security

none (not run; config-only change)

## Overall

APPROVED

No blockers. Follow-up outside this repo: tooling that reads `dav.log` by path
must switch to `dav-YYYY-MM-DD.log` (noted in implementation.md).
