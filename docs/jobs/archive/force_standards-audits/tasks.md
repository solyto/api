# Tasks: Standards: audits

id: force
analyst: @analyst
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

## Scope

The deliverable is **one report** in `docs/reports/` covering the five
"Audits" items from `docs/STANDARDS_BACKLOG.md`. This job is read-only on
application code: **no changes to `app/`, `routes/`, `config/`, tests,
`docs/STANDARDS.md` or `docs/STANDARDS_BACKLOG.md`.** Fixes are handled in a
follow-up job (brief, last line). Each finding should name file:line, the
rule it is judged against, a verdict (OK / violation / owner decision), and
a recommendation — the report recommends, it does not decide where the
standard says the owner decides (OQ-7, rule 1.4 keep/drop).

Suggested file: `docs/reports/standards-audits-2026-10-13.md` (the only
existing report, `deployment-readiness-1.0.15-to-main.md`, uses a plain
kebab-case name; follow that).

## Drift noticed during planning (report should confirm and state it)

The backlog counts/line numbers are from earlier on 2026-10-13 and do not
all match the current tree:

- `grep -rn '\$request->validate(' app` finds **21** sites, not 22, and
  there is no hit at `TimeTrackingEntryController.php:158`; the file has
  hits at `:260` and `:384` instead.
- `routes/api.php` has `->name('x.')` on **11** groups (`:121, 124, 140,
  242, 253, 263, 270, 279, 289, 297, 392`) plus one route-level
  `->name('uploadCover')` at `:299`, not seven.
- Jobs: 18 files in `app/Api/*/Jobs`, 4 set `$tries`/`$timeout`
  (`SyncFeed`, `SyncFeeds`, `DeleteOldFeedItems`, `ProcessExport`) → 14 on
  defaults — matches the backlog.
- Method line counts (60+) were not re-measured during planning.

## Task breakdown

TASK-1: Create the report skeleton (title, date, branch, scope statement, method/commands used, one empty section per audit item, summary table placeholder).
  files: docs/reports/standards-audits-2026-10-13.md (new)
  depends: none
  risk: low — new docs file only.

TASK-2: Audit every inline `$request->validate()` site against rule 3.1 — for each of the 21 hits record controller/action, HTTP verb + route, field count, whether the action is an `update`, and whether the same shape exists elsewhere; explicitly re-locate the `TimeTrackingEntryController` six-field case and note the count/line drift.
  files (read): app/Api/Todos/Controllers/TodoController.php, app/Api/Todos/Controllers/TodoWorkspaceController.php, app/Api/Feeds/Controllers/FeedController.php, app/Api/CheckIn/Controllers/CheckInController.php, app/Api/Notes/Controllers/NoteController.php, app/Api/Libraries/Controllers/Library{Recipe,Movie,Game,Book,Music}Controller.php, app/Api/Notifications/Controllers/NotificationSettingsController.php, app/Api/TimeTracking/Controllers/TimeTrackingEntryController.php, app/Api/Calendars/Controllers/{Calendar,EventAttachment}Controller.php, app/Api/Users/Controllers/PasskeyController.php, app/Api/Shortcuts/Controllers/ShortcutController.php, routes/api.php, docs/STANDARDS.md §3.1
  files (write): docs/reports/standards-audits-2026-10-13.md
  depends: TASK-1
  risk: low — read-only; main pitfall is judging "update" by method name only, so map each action to its route verb (PUT/PATCH) too.

TASK-3: Audit the 14 jobs on default `$tries`/`$timeout` against rule 9.2 — list all 18 jobs with current values, and for `GrabMusicReleases`, `GrabBookReleases`, `GrabMovieReleases`, `ImportCalendars`, `ImportContacts`, `ScaleCovers` record what external calls/loops they do, how they are dispatched/scheduled, and a recommended `$tries`/`$timeout` with one-line reason; the other eight get a one-line "default is fine because …" or "needs a look".
  files (read): app/Api/*/Jobs/*.php (18 files), routes/console.php, dispatch sites (grep `::dispatch` / `dispatch(`), config/queue.php (default retry_after), docs/STANDARDS.md §9.2
  files (write): docs/reports/standards-audits-2026-10-13.md
  depends: TASK-1
  risk: medium — recommended timeouts must stay below the Redis queue `retry_after` or jobs double-run; the report must check and cite that value rather than guess.

TASK-4: Document OQ-7 — describe what `ShareCalendarRequest` validates for `friend_id`, how `CalendarController` uses it (ownership/scope checks, what happens for a non-friend user id), what the `Friend` model offers (relations/scopes usable for a check), and present the two options (restrict to friends vs. rename/keep any user) with consequences; mark as owner decision, no verdict.
  files (read): app/Api/Calendars/Requests/ShareCalendarRequest.php, app/Api/Calendars/Controllers/CalendarController.php, app/Api/Users/Models/Friend.php, any calendar sharing service it calls, related Calendars tests, docs/STANDARDS.md §1.2, §3.2, OQ-7
  files (write): docs/reports/standards-audits-2026-10-13.md
  depends: TASK-1
  risk: medium — touches authorization semantics (sharing data with arbitrary users); sharing may also reach DAV sharing tables on `pgsql`, which must be flagged, not changed.

TASK-5: Re-measure the six methods over 60 lines (`BggService::getGameDetails`, `RepairCorruptedVCards::handle`, `PasskeyService::register`/`authenticate`, `ImportFeeds::importFromGit`, `TodoService::parse`), confirm current lengths, count callers of each, and record per method "keep (parser/protocol step, single caller)" or "split candidate (second caller exists)"; optionally note any other non-DAV method now over 60 lines found by the same measurement.
  files (read): app/Api/Libraries/Services/External/BggService.php (:97), app/Api/Contacts/Commands/RepairCorruptedVCards.php, app/Api/Users/Services/PasskeyService.php (:52, :139), app/Api/Feeds/Commands/ImportFeeds.php (:36), app/Api/Todos/Services/TodoService.php (:58)
  files (write): docs/reports/standards-audits-2026-10-13.md
  depends: TASK-1
  risk: low — read-only; state how lines were counted (signature to closing brace) so numbers are reproducible.

TASK-6: Audit route names against rule 1.4 — list every `->name()` in `routes/api.php` and `routes/web.php`, grep `route(`, `to_route(`, `URL::route`, `redirect()->route`, `routeIs`, `Route::has` and tests for callers, confirm none exist except the Telegram webhook, note the count drift (11 groups + 1 route, not 7), and lay out "keep uniformly" vs "drop uniformly" with consequences; mark as owner decision.
  files (read): routes/api.php, routes/web.php, app/**, tests/**, resources/**, docs/STANDARDS.md §1.4
  files (write): docs/reports/standards-audits-2026-10-13.md
  depends: TASK-1
  risk: low — read-only; caller grep must include tests and views, otherwise "no caller" may be wrong.

TASK-7: Fill the summary table (item, rule, verdict, recommended follow-up, owner decision needed y/n) and a "Backlog corrections" list of counts/line numbers that should be updated in `STANDARDS.md` / `STANDARDS_BACKLOG.md` by the follow-up job (do not edit those files here).
  files (write): docs/reports/standards-audits-2026-10-13.md
  depends: TASK-2, TASK-3, TASK-4, TASK-5, TASK-6
  risk: low — summary only.

## Unclear / for the owner

- The brief says "decide" for jobs (9.2) and "keep or drop" for route names
  (1.4), but also "We'll handle actual issues in another job". These tasks
  read that as: the report **recommends**, the owner decides, nothing in
  code changes. If the owner wanted decisions applied here, scope is larger.
- None of the five items is marked **(approval)** in the brief.
