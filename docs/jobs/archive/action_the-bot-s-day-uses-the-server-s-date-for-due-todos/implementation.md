# Implementation: the bot's /day uses the server's date for due todos

id: action
developer: @developer
date: 2026-11-22

## Summary

`/day` now lists due todos by the user's date (their timezone setting, falling back to UTC) instead of the server's UTC date, and always orders them by due date, so overdue todos come before today's. `composer test` and `composer analyse` both pass.

## Changes

TASK-1: `app/Bots/SolytoBot.php`: `dayCommand()` gets the timezone once from `$this->user` and passes `today($timezone)` to `TodoService::due()`. It also uses the same timezone to format appointment times, so the separate `$this->connection->user` lookup is gone.
TASK-2: `app/Bots/Tests/SolytoBotTest.php`: two new `/day` cases. In one the user's date is ahead of UTC (Europe/Berlin, chat 605), in the other it is behind (America/New_York, chat 606). Both fail without the TASK-1 change.
TASK-3: `app/Api/Todos/Services/TodoService.php`: `due()` now always orders by `due_at` and applies the limit only when one is given. The docblock is updated to match. Added a test for the order of the unlimited call in `TodoServiceTest`, and the existing UTC `/day` test now checks that overdue todos come before today's.

## Known issues / follow-ups

Other places that use the server's date (`listDueDate()`'s `now()->subDays(30)`, scheduled reminders) are out of scope, as the tasks say.
