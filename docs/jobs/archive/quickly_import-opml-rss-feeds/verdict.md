# Verdict: import opml rss feeds

id: quickly
reviewer: reviewer
date: 2026-11-13

## Review

Diff `main...HEAD` touches only job files and the new report
`docs/reports/opml-feed-import-2026-11-13.md`. No application code, routes,
config, migrations or dependencies changed, as the report-only brief requires.

TASK-1: PASS
notes: Report §2 matches the code. Spot-checked: `ImportFeeds::parseOpml` (`:102-130`, top-level `body->outline` only, `title`/`xmlUrl` only, `\RuntimeException`), `FeedService::createSubscription` (`:83-120`, synchronous `getFeedData`, typed exceptions, `SyncFeed` dispatch), `getAvailableFeeds`/`searchFeeds` list every `Feed`, private `plainTitle`, no unique index on `feeds.url` / `(user_id, feed_id)`, `FeedController` 409/422 mapping, `routes/api.php:385-399`. A few line refs are off by one or two (e.g. `FeedReader.php:18-47` really starts at `:18`/`:33`), which is fine for a report.

TASK-2: PASS
notes: §3 covers the OPML 2.0 spec and all eight readers from the task, with nesting, attribute variants (`xmlURL`, `url`, `text`-only titles), non-outline elements, encoding and size. Sources are cited in §10, and the one uncited estimate (bytes per entry) is labelled as such.

TASK-3: PASS
notes: §4 compares sync vs. create-now/validate-later vs. upload→select→job→poll against the real patterns (`Calendars ImportController::startImport/selectCalendars/getState` at `:62/108/145`, `CalendarImport::saveState` `:210-218`, `ImportCalendars` 1800 s loop `:31`, `NotesImportExportController::import` `:60`, `SyncFeeds` fan-out). It recommends no new abstractions, and the service shape follows `NoteImportService`.

TASK-4: PASS
notes: §5 is backed by code: `FeedReader` has no host check, `OutboundUrlGuard` is used only by `RemoteCalDavClient` (verified by grep), and the guard and manual redirect-following refs (`OutboundUrlGuard.php:42-69`, `RemoteCalDavClient.php:332-346, 373-391`) are correct. XML findings come from a stated libxml probe. Limits, the named limiter (the `account-deletion` style in `app/Shared/Providers/AppServiceProvider.php:81-83`), and the shared-table and hourly-sync load are all covered.

TASK-5: PASS
notes: The report is at the specified path and has every section the task asked for: summary, current state with refs, options, API contract sketch, OpenAPI, tests, effort, i18n (none), open decisions (folders, SSRF-first, invalid feeds, limits, preview, race, export), sources. Migrations are flagged as human decisions, and the `pgsql` connection is explicitly left alone.

Checks: `composer analyse` exit 0. `composer test` (full suite) passed in 3 of 4 runs. One run reported 1 failed test that did not reproduce in three reruns. This branch has no code changes, so that is an existing flaky test, not something this job caused.

## Security

none (report-only; it documents an existing SSRF gap in `FeedReader` as a follow-up job)

## Overall

APPROVED

No blockers.
