# Backlog: dissolve the IntegrationGateway

created: 2026-11-21

## What

`App\Shared\Services\IntegrationGateway` sits between two callers and the
module services:

- the Telegram bot (`app/Bots/SolytoBot.php`): quick-add, todos, due todos,
  today's appointments
- widgets (`app/Api/Widgets/Services/WidgetService.php`): due todos, events,
  completing todos, time tracking, check-in

The MCP tools (`app/Mcp/Tools/*`) don't use it; they call module services directly.

Its main job is letting Widgets, a module in `app/Api`, reach other modules
without breaking STANDARDS §12.8. Otherwise:

- **It's a union of caller-specific methods, not a shared interface:**
  `widgetEvents()`, `dueTodos()` next to `dueTodosInUserTimezone()`,
  `timeTrackingProjectsByLastUse()`.
- **It holds domain logic that belongs in the services,** where REST can't
  reuse it: the time-tracking switch (`startTimeTracking()`, which stops other
  timers in a transaction with a lock) and the check-in upsert (`setCheckIn()`,
  including the case where two requests create the same day at once).
- **It stores the user** (`setUser()` plus `checkAuth()`), the shape §4.1 rules
  out for services. The callers already authenticate before calling it.

Owner decision (2026-11-21): each entry point gets its own adapter that calls
the owning module's services directly, as controllers do. Shared logic lives in
those services or in a common service (as `QuickAddService` already does).

## Suggestion

One job, with ordered tasks, one commit each. The full suite stays green after
every task. Behaviour must not change; no API response or bot message changes.

1. **Clarify §12.8 in STANDARDS.** Domain modules still don't call each other's
   services. Entry points and aggregators (`app/Bots`, `app/Mcp`, Widgets) may,
   like controllers. Update §12.8's mention of the gateway accordingly.
2. **Bot tests with real data.** All 13 tests in `app/Bots/Tests/SolytoBotTest.php`
   mock the gateway, so they can't catch a change in behaviour. Add tests for
   `/day`, the todo list and quick-add that create real records and check the
   reply. They must pass against the current code.
3. **Move the logic into the owning services, with the gateway delegating:**
   - the time-tracking switch, the running entry and projects by last use →
     `TimeTrackingService`
   - the check-in upsert → `CheckInService`
   - due todos → `TodoService`

   Gateway methods become one-line calls. Keep the transaction and
   `lockForUpdate()` in the switch, and the `UniqueConstraintViolationException`
   handling in the upsert. Add service tests for the moved logic.
4. **Switch `WidgetService` to the services.** All 51 tests in
   `app/Api/Widgets/Tests/WidgetApiTest.php` must pass unchanged. Inject
   `CalendarService` (bound `scoped`, `AppServiceProvider.php:33`):
   `unavailableCalendars()` and `credentialsRejectedCalendars()` read state that
   the event listing set on that same instance.
5. **Switch `SolytoBot` to the services.** The tests from task 2 must pass
   unchanged. Replace the gateway-mock tests.
6. **Delete `IntegrationGateway`** once nothing references it, along with
   `IntegrationAuthException` if unused. Update docs that mention the gateway
   (STANDARDS §12.8, the `QuickAddService` notes, `docs/TESTING.md` if listed).

The `/day` timezone bug (`bot-day-timezone.md`) is a separate, intended change
in behaviour. Fix it separately, not inside this job.
