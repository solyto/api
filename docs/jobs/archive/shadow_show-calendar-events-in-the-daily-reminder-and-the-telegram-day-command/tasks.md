# Tasks: show calendar events in the daily reminder and the Telegram /day command

id: shadow
analyst: analyst
date: 2026-10-29

## Task breakdown

TASK-1: Add a today-only event read to `CalendarService` (e.g. `listTodayEvents(User $user): array` of `EventDTO`), mirroring `listWidgetEvents()`: accepted (non-`pending`) calendars, `events()->listExpanded()` bounded by today 00:00 to tomorrow 00:00 in the user's timezone (`$user->settings->timezone ?? 'UTC'`).
  files: app/Api/Calendars/Services/CalendarService.php
  depends: none
  risk: medium — the window must match the widget's semantics (exclusive end, floating all-day dates, west-of-UTC users per the existing widget tests); reuse the same `listExpanded` call shape rather than inventing new bounds.
  notes:
  - Build the bounds from Carbon (`Carbon::today($tz)` / `->addDay()`, converted to `\DateTime` since `listExpanded` takes `\DateTime`), not `new \DateTime('today')`: the reminder tests freeze time with `Carbon::setTestNow('2026-08-14 07:00:00')`, which native `DateTime` ignores. Do not change `listWidgetEvents()` itself (unrelated refactor).
  - Caching: either reuse the widget cache-key pattern with a distinct segment (e.g. `'today', $date`, TTL `CACHE_TTL_EVENTS_CURRENT`) or skip caching; event writes already `forgetByPrefix([events, user, calendarId])`, so a key under that prefix stays invalidated correctly. Developer's call; no new abstraction.

TASK-2: Switch `IntegrationGateway::todayAppointments()` to the new `CalendarService` method, returning `collect(...)` of `EventDTO`s, and drop the `CalendarEntry`/`Carbon` imports there.
  files: app/Shared/Services/IntegrationGateway.php
  depends: TASK-1
  risk: low — signature stays `Collection`, so `SolytoBot` and the existing mocks keep working; add a `@return Collection<int, EventDTO>` docblock.

TASK-3: Update `SolytoBot::dayCommand()` to read `EventDTO` fields: `isAllDay`, `title`, and format `startDate` as `H:i` after converting to the connected user's timezone (instead of `Carbon::createFromTimestamp($appointment->start_date)`).
  files: app/Bots/SolytoBot.php
  depends: TASK-2
  risk: medium — the user/timezone must come from the authenticated connection (check how `auth()` sets `$this->connection` / the gateway user); expanded timed occurrences may carry the event's own TZID, so convert with `setTimezone`, don't just `format`. Remove the `Carbon` import only if it becomes unused.

TASK-4: Switch `SendDailyDayRemindersCommand` to load events via the new `CalendarService` method (resolved from the container) and pass them as a collection of `EventDTO`s; remove the `CalendarEntry` read and the now-unused timestamp bounds.
  files: app/Api/Users/Commands/SendDailyDayRemindersCommand.php
  depends: TASK-1
  risk: low — straight swap of the data source; the 07:00 gate and todos query stay as they are.

TASK-5: Type `DailyDayReminderNotification`'s parameters so PHPStan sees `title` on both: `todos` as an Eloquent `Collection<int, Todo>` and `events` as `Illuminate\Support\Collection<int, EventDTO>` (the events are no longer models, so the param type must change from `Illuminate\Database\Eloquent\Collection`).
  files: app/Api/Users/Notifications/DailyDayReminderNotification.php
  depends: TASK-4
  risk: low — only the constructor types/docblocks change; `buildSummary()` output and the existing `notifications.daily_day_reminder_*` strings stay. No new user-facing strings expected; if any are added, `en` only and hand off to `translator`.

TASK-6: Delete the `DailyDayReminderNotification.php` entry from `phpstan-baseline.neon` (the file then has an empty `ignoreErrors`; keep it valid or remove its include from `phpstan.neon` if the baseline becomes empty — check how it's included) and confirm `composer analyse` exits 0.
  files: phpstan-baseline.neon, possibly phpstan.neon
  depends: TASK-5
  risk: low — if analyse still reports errors in this file, fix the types in TASK-5 rather than re-adding an ignore.

TASK-7: Tests — reminder command: user (UTC and one non-UTC timezone is a plus) with a timed event, an all-day event and a daily recurring event (started yesterday) today, plus one event tomorrow; assert the sent `DailyDayReminderNotification` carries exactly the three today titles (`Notification::assertSentTo` with a callback on `$notification->events`).
  files: app/Api/Users/Tests/CommandTest.php
  depends: TASK-4, TASK-5
  risk: medium — events must be created through DAV the way `CalendarApiTest` does (`POST /api/v1/calendars/{id}/events` on the user's default calendar); with `Carbon::setTestNow` frozen at 07:00, event dates must be derived from the frozen "today" and the service must use Carbon-based bounds (TASK-1). Cache: confirm the test cache driver doesn't leak between tests.

TASK-8: Tests — `/day`: same fixture (timed, all-day, recurring today; one tomorrow) against the real `IntegrationGateway` (not a mock), asserting the Telegram `sendMessage` text contains the all-day title, `HH:MM <timed title>` in the user's timezone, and the recurring title, and not the tomorrow title. Keep the existing mocked tests unless they become redundant.
  files: app/Bots/Tests/SolytoBotTest.php, possibly app/Shared/ (gateway unit test, optional)
  depends: TASK-2, TASK-3
  risk: medium — the bot resolves the gateway via constructor injection; the test must construct `SolytoBot` with `app(IntegrationGateway::class)` and the connected user, with `Http::fake()` for Telegram.

TASK-9: Run `composer test` and `composer analyse` in the worktree; both must exit 0.
  files: none
  depends: TASK-1 … TASK-8
  risk: low — gate only.

TASK-10: Report (in implementation.md, no code change) on remaining `CalendarEntry` users, for a separate flagged follow-up — do NOT remove anything in this job.
  files: docs/jobs/.../implementation.md (report only)
  depends: TASK-2, TASK-4
  risk: low — informational.
  notes: after TASK-2/4, remaining references are `Calendar::entries()` (app/Api/Calendars/Models/Calendar.php:46-49), `CalendarEntry` model + `CalendarEntryFactory`, and `CalendarApiTest.php:302,331`, which use `CalendarEntry` ids for the event-attachment endpoints (`calendars/events/{eventId}/attachments/...`, `EventAttachmentController`/`EventAttachmentService`). So the model is not dead yet: attachments key on an id that the DAV events would also need to supply. Removing the model/table needs a decision on what `eventId` means for attachments — flag as a backlog item, out of scope here.

## Open points (not blocking)

- Reminder scope: the brief says "today's events"; multi-day events that started before today and are still ongoing will appear (same as the widget's "Ongoing Multi Day" behaviour). Assumed acceptable.
- Reminder lists events by title only (no time), as today; adding times would be a new string format — not in scope unless requested.
