# Tasks: the bot's /day uses the server's date for due todos

id: action
analyst: @analyst
date: 2026-11-22

<!-- Produced by @analyst from brief.md. -->

## Task breakdown

TASK-1: In `SolytoBot::dayCommand()`, resolve the user's timezone once at the top (`$this->user->settings->timezone ?? 'UTC'`), pass `today($timezone)->toDateString()` to `TodoService::due()`, and reuse the same `$timezone` for the appointment time formatting. This replaces the separate lookup through `$this->connection->user` on line 107.
  files: app/Bots/SolytoBot.php
  depends: none
  risk: low. It changes one call site, and `$this->user` is the same user as `$this->connection->user` (set in `auth()`, line 285).

TASK-2: Add a `/day` test to the "SolytoBot with real records" block in `SolytoBotTest`. The two scenarios can be separate `it()` cases:
  - Ahead of UTC: a `Europe/Berlin` user with `Carbon::setTestNow('2026-08-14 23:30:00')` (UTC), which is already 2026-08-15 in Berlin. The reply lists a todo due `2026-08-15`.
  - Behind UTC: an `America/New_York` user with `Carbon::setTestNow('2026-08-15 02:00:00')` (UTC), which is still 2026-08-14 in New York. The reply does not list a todo due `2026-08-15`, and does list one due `2026-08-14`.

  Use new unique chat ids. `afterEach` already resets `setTestNow`.
  files: app/Bots/Tests/SolytoBotTest.php
  depends: TASK-1
  risk: low. The test is self-contained, and the existing UTC `/day` test keeps covering the today/overdue/future/completed cases.

TASK-3: Make `TodoService::due()` always order by `due_at` and apply the limit only when one is given. Update the docblock, because "ordered by due date only when limited" would no longer be true. `/day` then lists overdue todos before today's, the same order as the widget.
  files: app/Api/Todos/Services/TodoService.php, app/Api/Todos/Tests/TodoServiceTest.php (optional: assert the order for the unlimited call), app/Bots/Tests/SolytoBotTest.php (optional: assert `Overdue` comes before `Due today` in the existing `/day` test)
  depends: none
  risk: low. The widget already passes a limit, so its behaviour stays the same. The only visible change is the line order in `/day`, which was undefined before (insertion order).

## Decisions

- **Ordering (brief point 1).** The brief leaves this open. My default is to order `/day` by due date (TASK-3). The job `remarkable` kept the ordering conditional only so that `/day` would not change in that job, and it named this fix as the place to unify the two. TASK-3 is independent of the bug fix: if a human prefers to keep the current `/day` order, drop TASK-3. TASK-1 and TASK-2 stand on their own.

## Out of scope

- Other places that use the server's `today()`/`now()` (for example `listDueDate()`'s `now()->subDays(30)`, and the scheduled reminders). They are not part of this brief.
- No user-facing strings change, so no translator handoff is needed.
