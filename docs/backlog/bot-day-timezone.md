# Backlog: the bot's /day uses the server's date for due todos

created: 2026-11-21

## What

`SolytoBot::dayCommand` (`app/Bots/SolytoBot.php:94`) lists due todos through
`IntegrationGateway::dueTodos()` (`IntegrationGateway.php:59-67`), which
compares `due_at` (a `date` column) with `today()`. The server runs in UTC
(`config/app.php:97`), so "today" is UTC's date, not the user's:

- In Germany (UTC+1/+2), between midnight and 1–2 am the bot still uses
  yesterday's date, so todos due on the new day are missing.
- In the Americas, in the evening the bot already counts tomorrow's todos as due.

The appointments in the same reply (`todayAppointments()`) use the user's
timezone, so one reply can mix two different "todays".

`dueTodosInUserTimezone()` (`IntegrationGateway.php:86-96`, used by widgets)
already does this correctly.

## Suggestion

1. Use the user's timezone in `/day`: either switch to
   `dueTodosInUserTimezone()` (it adds a limit and orders by due date, so
   check the reply still reads right) or change `dueTodos()` the same way.
2. Add a bot test with a user in a non-UTC timezone and a todo due on the
   user's date but not on UTC's.

Independent of `dissolve-integration-gateway.md`. If that job runs first, this
fix falls out of its single due-todos method in `TodoService`.
