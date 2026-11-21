# Verdict: dissolve the IntegrationGateway

id: remarkable
reviewer: reviewer
date: 2026-11-21

## Review

Reviewed `git diff main...HEAD` on `feature/remarkable_dissolve-the-integrationgateway`.
Full suite run in the worktree: `composer test` exit 0 (2030 tests, all
warnings are the environment's missing `.env`, no failures);
`composer analyse` exit 0, "No errors".

TASK-1: PASS
notes: `docs/STANDARDS.md` §12.8 rewritten (domain modules still don't call each
other's services; `app/Bots`, `app/Mcp`, Widgets may, passing the `User` per
call). Owner decision recorded under `## Decisions`.

TASK-2: PASS
notes: `app/Bots/Tests/SolytoBotTest.php` "with real records" block has the five
specified tests (`/todos` scoping, `/day` UTC with today/overdue/future/completed,
`EMPTY_DAY`, link auto-commit, type selection → todo). They check the exact
reply and the stored records, and build the bot via `app(SolytoBot::class)`.
The block is byte-identical between its commit (435584b) and HEAD, so the
later tasks didn't change it.

TASK-3: PASS
notes: `TimeTrackingService::runningEntry/projectsByLastUse/switchTo` match the
gateway's code line for line, keeping `DB::transaction(..., 3)`, `lockForUpdate()`
and the NULL-ordering comment. Service tests in `TimeTrackingServiceTest.php`
cover the running entry, order with never-used projects, limit/except, the
switch, and the no-op.

TASK-4: PASS
notes: No-op as planned. The widget calls `CheckInService::builtInValuesOn`/`setBuiltInValue`
and `CheckInTrackerService::enabledBuiltInKeys` directly.

TASK-5: PASS
notes: `TodoService::due()` keeps the bot unordered and the widget ordered and
limited. The widget's date (`CarbonImmutable::now($tz)->startOfDay()->toDateString()`)
equals the old `today($tz)->toDateString()`. Tests in `TodoServiceTest.php`
cover the boundary, completed, null-date and user scoping, plus order and limit.

TASK-6: PASS
notes: `WidgetService` injects the five services. `setUser()` is gone. The
already-completed and already-stopped guards moved unchanged, and
`unavailableCalendars()`/`credentialsRejectedCalendars()` read the injected
scoped `CalendarService`. `WidgetApiTest.php` is untouched and green.

TASK-7: PASS
notes: `SolytoBot` injects `QuickAddService`, `TodoService` and `CalendarService`,
and stores `$user` in `auth()`. The call mapping matches the plan. The
gateway-mock tests were replaced as planned, and the error path now mocks
`QuickAddService`.

TASK-8: PASS
notes: Both classes deleted. No references remain in `app`, `tests`, `routes`,
`config` or `bootstrap`.

TASK-9: PASS
notes: STANDARDS §4.2, §10.2 and §12.8 updated and the historical note marked
superseded. `docs/AGENTS.md` updated. The backlog entries
(`bot-day-timezone.md`, `cache-followups.md`) are updated on disk. They are
uncommitted because `docs/backlog/` is hub-managed and ignored in this repo,
so this is expected and not a finding.

## Security

none (not run). No new endpoints or auth surface. Every caller authenticates
before the user-bound calls (bot `auth()`, widget routes under `auth:sanctum`).

## Overall
APPROVED

Behaviour is preserved, the moved concurrency code is unchanged, the full
test suite and analysis pass, and there are no blockers.
