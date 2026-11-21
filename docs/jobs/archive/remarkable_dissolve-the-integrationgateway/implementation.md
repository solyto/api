# Implementation: dissolve the IntegrationGateway

id: remarkable
developer: developer
date: 2026-11-21

## Summary

`IntegrationGateway` and `IntegrationAuthException` are gone. The bot and the
widgets call module services directly and pass the `User` per call. The
timer switch, the running entry and projects by last use now live in
`TimeTrackingService`, and due todos in `TodoService::due()`. No API response
or bot reply changed. `composer test` (exit 0) and `composer analyse` (exit 0,
no errors) both pass.

## Changes

TASK-1: `docs/STANDARDS.md` §12.8 now says domain modules still don't call
each other's services, but entry points and aggregators (`app/Bots`,
`app/Mcp`, Widgets) may, as controllers do. Shared logic goes in the owning
service or in a common `app/Shared` service. The owner decision is recorded
under `## Decisions`.

TASK-2: `app/Bots/Tests/SolytoBotTest.php` gets a "with real records" block
with five tests that check the exact reply text and the stored records:
`/todos` with user scoping, `/day` with today, overdue, future and completed
todos (UTC user, `setTestNow`), the `/day` empty text, quick-add auto-commit
of a link, and type selection creating a todo. The bot is built through
`app(SolytoBot::class)`. These tests passed on the old code and still pass
unchanged.

TASK-3: Added `TimeTrackingService::runningEntry()`, `projectsByLastUse()` and
`switchTo()`. `switchTo()` keeps `DB::transaction(..., 3)`, `lockForUpdate()`
and the NULL-ordering comment. The gateway delegated to them until TASK-8. New
tests are in `app/Api/TimeTracking/Tests/TimeTrackingServiceTest.php`.

TASK-4: Nothing to do. The check-in upsert and its
`UniqueConstraintViolationException` handling were already in
`CheckInService`.

TASK-5: Added `TodoService::due(User, string $date, ?int $limit)`. It orders
by `due_at` and applies the limit only when a limit is given, so `/day` keeps
the order it had. New tests are in `app/Api/Todos/Tests/TodoServiceTest.php`.
I put them in a new `*ServiceTest.php` file instead of `TodoTest.php`, because
`docs/TESTING.md` treats `TodoTest.php` as the legacy factory/model file.

TASK-6: `WidgetService` now has `CalendarService`, `TodoService`,
`TimeTrackingService`, `CheckInService` and `CheckInTrackerService` injected,
and `setUser()` is gone. The already-completed and already-stopped no-op
guards moved into `WidgetService` unchanged. `unavailableCalendars()` and
`credentialsRejectedCalendars()` read the same injected `CalendarService`
instance that does the listing (it is bound `scoped`). `WidgetApiTest` is
unchanged and green.

TASK-7: `SolytoBot` now has `QuickAddService`, `TodoService` and
`CalendarService` injected, and keeps the authenticated `User` (set in
`auth()`). In the tests:
- The gateway-mock tests that the TASK-2 tests now cover were removed.
- The error-path test mocks `QuickAddService` instead of the gateway.
- The fall-through routing test now uses real records and checks
  `ADDED_LINK`.
- The connect, welcome and calendar `/day` tests build the bot with
  `app(SolytoBot::class)`.

TASK-8: Deleted `app/Shared/Services/IntegrationGateway.php` and
`app/Shared/Exceptions/IntegrationAuthException.php`. Nothing in `app`,
`tests`, `routes`, `config` or `bootstrap` referenced them any more.

TASK-9:
- `docs/STANDARDS.md`: §4.2's exception now names only `QuickAddService` and
  `ExportService`. §10.2 no longer has the `@throws` example. The historical
  correction note is marked superseded.
- `docs/AGENTS.md`: lists only `QuickAddService`.
- `docs/backlog/bot-day-timezone.md` and `docs/backlog/cache-followups.md`
  (item 2) now point at `TodoService::due()`/the bot's call site and at
  `TimeTrackingService::switchTo`. Note: `docs/backlog/` is ignored in this
  repo (hub-managed), so these two edits are on disk but not in a commit.

## Known issues / follow-ups

- `SolytoBot::commitWithType()` is an unused private method. I switched it to
  `QuickAddService` like the rest but didn't remove it; that's out of scope.
- `cache-followups.md` item 2 is still open: `switchTo()` still forgets the
  cache inside the uncommitted transaction. I moved it as-is because the brief
  said behaviour must not change.
- The `/day` timezone bug (`bot-day-timezone.md`) is now a one-line change at
  the bot's call site plus a test. It is left for its own job.
- Environment: this sandbox had no `vendor/`, `storage/framework/*` or `.env`.
  I ran `composer install` from the lock file and created the ignored storage
  directories. Every test prints a "missing `/workspace/.env`" warning, which
  comes from the environment, not from the code.
