# Implementation: widget endpoints: Your Day data and the complete-todo action

id: whisper
developer: developer
date: 2026-10-29

## Summary

New `app/Api/Widgets` module with `GET /v1/widgets/your-day` (display-ready
Your Day data in the generic widget format) and
`POST /v1/widgets/actions/todo.complete/{todo}` (idempotent complete). Both
are in a named `widgets.` group, so they work with the widget token. They are
rate-limited by a new `widgets` limiter. `composer test` and
`composer analyse` both exit 0.

## Changes

TASK-1: `AppServiceProvider`: new `widgets` limiter (60/min), keyed by the
personal access token id, then the user id, then the IP.

TASK-2: `IntegrationGateway`: added `dueTodosInUserTimezone(int $limit)`,
`widgetEvents()` and `completeTodo(Todo)`. `dueTodosInUserTimezone` returns
todos that are not completed with `whereDate(due_at) <= today` in the user's
timezone, oldest first. `completeTodo` does nothing if the todo is already
completed; otherwise it calls `TodoService::update(['is_completed' => true])`.
`dueTodos()` is unchanged.

TASK-3: `resources/lang/{en,de,fr,es,it}/widgets.php`. The de/fr/es/it
strings are the frontend's existing Your Day copy (`your_day`, `nothing_today`,
`events`, `due_today`, `coming_up`, `all_day`, `due_overdue`), not new
translations. No translator agent was available in this session, so
**the translator should still review them**, mainly `overdue` (`<word> · :date`)
and the section titles.

TASK-4/5: `Widgets/Services/WidgetService` (one service for both endpoints,
instead of the suggested `YourDayService`):
- `today` holds events that start before local midnight tomorrow, so events
  that started earlier and are still running land here, not in `upcoming`.
  All-day events come first, then by start time. `upcoming` holds the rest,
  sorted by start.
- All-day events and todos send `Y-m-d` dates. All-day dates are floating, so
  they are not converted to the user's timezone. Timed events send ISO
  timestamps with the user's offset. Optional keys are left out when null.
- Event row `id` = `<uri>#<original occurrence start Ymd\THis>`, so it is
  unique per occurrence of a recurring event.
- Formats: the real values come from the frontend (`LocalizationSettings.svelte`).
  `time_format` is `H:i` | `g:i A` | `g:i a`, which are PHP formats and are
  used as-is; anything else falls back to `H:i`. `date_format` uses
  `dd`/`mm`/`YYYY`/`YY` tokens. Short dates (overdue subtitle, upcoming
  `time_label`) copy the app's `formatDateWithoutYear()`: the year is dropped
  and leftover separators trimmed (`dd.mm.YYYY` → `27.10`,
  `mm/dd/YYYY` → `10/27`).
- `time_label`: today: the start time, or "all day". For a timed event that
  started before today it is left out. Upcoming: short date, plus the time
  for timed events.
- The language comes from `settings.language` if it is in `config('app.locales')`,
  otherwise `en`. Each section is capped at 20 rows.

TASK-6: `Widgets/Resources/YourDayResource` with `@OA\Schema` `YourDayWidget`.
The schema documents the full row format inline.

TASK-7: `Widgets/Controllers/WidgetController` (`yourDay`, `completeTodo`),
with OpenAPI annotations for 200/401/403/404/426/429.

TASK-8: `routes/api.php`:
`Route::prefix('widgets')->name('widgets.')->middleware('throttle:widgets')`
inside the `auth:sanctum` + `TrackApiTraffic` group. The routes are
`widgets.your-day` and `widgets.actions.todo-complete`.

TASK-9: `Widgets/Tests/WidgetApiTest.php` covers every item of brief step 7,
plus recurring-event expansion, the 20-row cap and 401 without a token. It
needed one change outside the module: `CalendarService::listWidgetEvents()`
built its window with native `new \DateTime('today')`, which ignores
`Carbon::setTestNow()`, so the timezone tests could not pin a time. It now
uses `Carbon::today($tz)` the same way `listTodayEvents()` does. Production
behaviour is unchanged, and the cache key is still the date string.

TASK-10: skipped on purpose. The probe route in `WidgetTokenTest` also covers
an unnamed route and the route name the gate sees, so replacing it is not a
direct swap. The real routes are covered in `WidgetApiTest`.

TASK-11: `STANDARDS.md` 1.4 now counts the `widgets.` group, with the token
gate as its caller.

TASK-12: `composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- Translator review of `resources/lang/{de,fr,es,it}/widgets.php` (see TASK-3).
- Flaky tests that are not part of this job: in one full run, two
  `ExportTest` cases failed (they passed in 5 separate runs and in the next
  full run), and the `TimeTrackingTest` "short entry" factory can produce
  exactly 60 minutes.
- The worktree had no `vendor/` and no `storage/framework/*` directories.
  I ran `composer install` from the lock file and created those directories
  (both untracked).
- `.env.testing` showed as modified before this job started. It was not
  touched or committed.
