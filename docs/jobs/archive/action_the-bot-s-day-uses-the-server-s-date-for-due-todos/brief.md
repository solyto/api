# Brief: the bot's /day uses the server's date for due todos

type: feature
id: action
branch: feature/action_the-bot-s-day-uses-the-server-s-date-for-due-todos
date: 2026-11-22
author: Leander Muskalla

## What

`SolytoBot::dayCommand` (`app/Bots/SolytoBot.php:98`) lists due todos through
`TodoService::due($user, today()->toDateString())` (`TodoService.php:56`),
which compares `due_at` (a `date` column) with the server's `today()`. The server runs in UTC
(`config/app.php:97`), so "today" is UTC's date, not the user's:

- In Germany (UTC+1/+2), between midnight and 1–2 am the bot still uses
  yesterday's date, so todos due on the new day are missing.
- In the Americas, in the evening the bot already counts tomorrow's todos as due.

The appointments in the same reply (`CalendarService::listTodayEvents()`) use the user's
timezone, so one reply can mix two different "todays".

The widget (`WidgetService::yourDay`, `WidgetService.php:57`) already passes
the user's date to `TodoService::due()`.

## Suggestion

1. Use the user's timezone in `/day`: pass
   `today($user->settings->timezone ?? 'UTC')->toDateString()` to
   `TodoService::due()` at the bot's call site. `due()` orders by due date
   only when given a limit; decide whether `/day` should be ordered too.
2. Add a bot test with a user in a non-UTC timezone and a todo due on the
   user's date but not on UTC's.

The gateway was dissolved in job `remarkable` (2026-11-21); this fix is now a
one-line change at the bot's call site plus the test.
