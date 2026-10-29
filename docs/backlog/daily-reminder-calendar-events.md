# Backlog: show calendar events in the daily reminder and the Telegram /day command

created: 2026-10-29

## What

The 7 a.m. daily reminder and the Telegram bot's `/day` command never list
calendar events. Both read today's events from the legacy `CalendarEntry`
model (`calendar_entries` table, from the Nextcloud integration), which
nothing writes since events moved to the native CalDAV server (`pgsql`).
Read them from DAV instead.

## Why

Users only ever get their todos, or "nothing on today", even on days full of
appointments. Nobody noticed because the tests stub the event source away
(`SolytoBotTest.php:158,185` mock `todayAppointments()` to return an empty
collection).

Affected code:

- `app/Api/Users/Commands/SendDailyDayRemindersCommand.php:49`
  (`CalendarEntry::forUser(...)->whereBetween('start_date', ...)`), passed as
  `events` to `DailyDayReminderNotification`, which prints `$event->title`.
- `app/Shared/Services/IntegrationGateway.php:69` (`todayAppointments()`),
  used by `SolytoBot::dayCommand()` (`app/Bots/SolytoBot.php:91-106`), which
  reads `is_all_day`, `start_date` (a timestamp) and `title`.

## Steps

1. Load today's events through `CalendarService`, the way
   `listWidgetEvents()` does (`CalendarService.php:113`): all accepted
   calendars, `events()->listExpanded()` so recurring events are included,
   bounded by today in the user's timezone. A today-only variant or a filter
   on the widget result; no new abstraction.
2. Use it in both places. Events become `EventDTO`s (`title`, `isAllDay`,
   `startDate` as `DateTimeInterface`), so adjust `dayCommand()` (format
   `startDate` in the user's timezone instead of `createFromTimestamp`) and
   the notification's `events` parameter type.
3. Tests: a user with one timed, one all-day and one recurring event today
   (and one tomorrow that must not appear) gets them in the reminder and in
   `/day`.
4. Remove the PHPStan baseline entry for
   `DailyDayReminderNotification.php` (`Model::$title`) if the typed events
   clear it.
5. Check whether anything else still reads `CalendarEntry`
   (`Calendar::entries()`, `CalendarEntryFactory`); if not, removing the
   model and the table is a separate, flagged change.

## Notes

- Behaviour change for users: reminders and `/day` start listing
  appointments. The reminder text strings already exist
  (`notifications.daily_day_reminder_events`); new or changed strings go to
  the `translator` agent.
- Reading DAV events touches no DAV schema or routes.
