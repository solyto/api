# Verdict: show calendar events in the daily reminder and the Telegram /day command

id: shadow
reviewer: reviewer
date: 2026-10-29

## Review

Reviewed `git diff main...HEAD`; all app changes are covered by tasks.md, nothing out of scope.

TASK-1: PASS
notes: `CalendarService::listTodayEvents()` has the same shape as `listWidgetEvents()`: it skips pending calendars and calls `listExpanded`. It uses a Carbon-based window from today to tomorrow in the user's timezone, so `setTestNow` applies. The cache key sits under the `[events, user, calendarId]` prefix, so event writes still invalidate it. `listWidgetEvents()` is unchanged.

TASK-2: PASS
notes: `todayAppointments()` returns `collect(listTodayEvents())` and has a `Collection<int, EventDTO>` docblock. The `CalendarEntry` and `Carbon` imports are removed.

TASK-3: PASS
notes: `dayCommand()` reads `isAllDay`, `title` and `startDate`, and formats the time with `setTimezone()` to the connected user's timezone. `$this->connection` is set by `auth()` (SolytoBot.php:260). `Carbon` is still imported because it is still used.

TASK-4: PASS
notes: The `CalendarEntry` query and the timestamp bounds are removed. Events are loaded through the container-resolved `CalendarService`. The 07:00 gate and the todos query are unchanged.

TASK-5: PASS
notes: `todos` is typed `?EloquentCollection<int, Todo>` and `events` is `?Support\Collection<int, EventDTO>`. No string changes, so no translator handoff is needed.

TASK-6: PASS
notes: The baseline is now `ignoreErrors: []` and its include in `phpstan.neon` stays. `composer analyse` exits 0 with `[OK] No errors`.

TASK-7: PASS
notes: The new dataset test in `CommandTest` runs for UTC and Europe/Berlin. Its fixture has a timed event, an all-day event, a recurring event that started yesterday, and an event tomorrow. It asserts exactly the three titles for today. Both runs pass.

TASK-8: PASS
notes: The new `/day` test uses the real `IntegrationGateway` and a Europe/Berlin user. It asserts `- Holiday`, `- 09:30 Dentist`, `- 08:00 Standup`, and that `Tomorrow Meeting` is absent. It passes. The existing mocked tests are kept.

TASK-9: PASS
notes: I ran both checks in the worktree. `composer test` exits 0: 1240 tests, 4272 assertions, no failures. The only warnings come from the missing `.env` in the worktree, which is an environment issue. `composer analyse` exits 0.

TASK-10: PASS
notes: `implementation.md` reports the remaining `CalendarEntry` users and removes nothing. This matches my grep: `Calendar::entries()`, the model, the factory, and the attachment tests in `CalendarApiTest`.

## Security

Not run.

## Overall

APPROVED

No blockers.
