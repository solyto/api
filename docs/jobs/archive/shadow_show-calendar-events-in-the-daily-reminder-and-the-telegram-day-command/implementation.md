# Implementation: show calendar events in the daily reminder and the Telegram /day command

id: shadow
developer: developer
date: 2026-10-29

## Summary

The 7 a.m. day reminder and the Telegram `/day` command now read today's
events from the DAV calendars (all accepted calendars, recurring events
expanded, bounded by today in the user's timezone) instead of the legacy
`calendar_entries` table. The notification is typed so PHPStan sees `title`
on todos and events, and the last PHPStan baseline entry is gone.
`composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `CalendarService::listTodayEvents()` mirrors `listWidgetEvents()`, but the window is today 00:00 to tomorrow 00:00 in the user's timezone. The bounds are built with Carbon so `Carbon::setTestNow()` applies. Results are cached under the existing `[calendar_events, user, calendarId, ...]` prefix (`'today', date`, 15 min TTL), so event writes invalidate them as before.
TASK-2: `IntegrationGateway::todayAppointments()` returns `collect(listTodayEvents())` (`Collection<int, EventDTO>`). Dropped the `CalendarEntry` and `Carbon` imports.
TASK-3: `SolytoBot::dayCommand()` reads `isAllDay`, `title` and `startDate` from the `EventDTO` and formats the time as `H:i` after `setTimezone()` to the connected user's timezone.
TASK-4: `SendDailyDayRemindersCommand` loads events through `CalendarService::listTodayEvents()`. Removed the `CalendarEntry` query and the timestamp bounds.
TASK-5: `DailyDayReminderNotification`: `todos` is `?EloquentCollection<int, Todo>` and `events` is `?Illuminate\Support\Collection<int, EventDTO>`. The constructor type for `events` changed from the Eloquent collection to the base collection.
TASK-6: `phpstan-baseline.neon` now has `ignoreErrors: []`. The include stays so later baselines have a home. `composer analyse` reports no errors.
TASK-7: `CommandTest`: a new dataset test (UTC, Europe/Berlin) creates a timed event, an all-day event, a daily recurring event that started yesterday, and an event tomorrow through the API. It asserts the reminder carries exactly the three titles for today.
TASK-8: `SolytoBotTest`: a new `/day` test runs against the real `IntegrationGateway` with a Europe/Berlin user and the same fixture. It asserts `- Holiday`, `- 09:30 Dentist` and `- 08:00 Standup` are in the message and `Tomorrow Meeting` is not. The existing mocked tests are unchanged.
TASK-9: `composer test` (1240 passed, warnings only from the missing `.env` in the worktree) and `composer analyse` both exit 0. The first full run after `composer install` had 2 failures I could not reproduce: three later full runs and repeated runs of the touched test files all passed.
TASK-10: see below.

## Known issues / follow-ups

- Remaining `CalendarEntry` users (nothing removed): `Calendar::entries()` (no callers in app code), the `CalendarEntry` model, `CalendarEntryFactory`, and `CalendarApiTest.php:302,331`. Those tests use `CalendarEntry` ids as the `eventId` for the event-attachment endpoints. `EventAttachmentService` takes a plain string `eventId` and does not touch the model. Removing the model and the `calendar_entries` table is a separate, flagged change: first decide what `eventId` means for attachments with DAV events (e.g. UID/URI), then move those tests onto DAV events.
- Ongoing multi-day events that started before today show up in the reminder and `/day`, the same way the widget handles them.
- To run in a fresh worktree, I ran `composer install` (from the lock, no new packages) and created the untracked `storage/framework/*` and `bootstrap/cache` directories.
