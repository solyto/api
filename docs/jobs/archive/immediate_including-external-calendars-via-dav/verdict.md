# Verdict: Including external calendars via DAV

id: immediate
reviewer: @reviewer
date: 2026-11-08

## Review

Diff `main...HEAD`: only the job files and the new
`docs/reports/external-calendars-via-dav-2026-11-08.md`. No code, migrations,
config, routes or DAV wiring changed — matches the report-only scope.

TASK-1: PASS
notes: Spot-checked: `37f9642` is the root commit; `SolytoBot.php:12,100` at `37f9642` references `NextcloudCalendarEntry`; `4803c35` and `5e2e4aa` touch the files claimed; `0d932a3`/`e2da36a` dates match. The report clearly states the pre-2026-04-07 history is missing. Small inaccuracy, not a blocker: `git log --grep=nextcloud -i` also matches `83d314c`, so `5e2e4aa` is not the only hit.

TASK-2: PASS
notes: Legacy design is rebuilt from migrations and surviving code, and the report keeps what it can show apart from what it can't (including "it worked fine").

TASK-3: PASS
notes: Verified `CalendarImport.php:181` hardcoded principal path, `:224-236` basic auth + 60-min cache of the DTO, `EventDTO::fromImport` nulling uri/etag, unused `importEventCustom`, no Subscriptions plugin in `DavServerFactory`, `CalendarSubscription::isExternal()`, `DavService::deleteForUser` deletion, no calendar jobs in `routes/console.php`.

TASK-4: PASS
notes: No earlier report found (I didn't find one either). Overlap with the vtodo and bot-traffic reports is linked, not repeated.

TASK-5: PASS
notes: Options A/B/C cover every dimension tasks.md asked for. Credential storage, SSRF and the ⚑ DAV wiring/pgsql-semantics points are flagged.

TASK-6: PASS
notes: Report is at the specified path, follows the existing report structure, and includes a comparison table, recommendation, follow-ups and open questions (read-only vs two-way, Google OAuth, limits).

TASK-7: PASS
notes: implementation.md filled in. Reviewer re-ran both checks on this worktree: `composer test` exit 0 (0 failures; the warnings are environment-related), `composer analyse` exit 0 ("No errors").

## Security

Not run separately. Report-only change, no code.

## Overall

APPROVED

No blockers.
