# Tasks: dissolve the IntegrationGateway

id: remarkable
analyst: analyst
date: 2026-11-21

<!-- Produced by @analyst from brief.md. -->

Branch checked: worktree HEAD is `feature/remarkable_dissolve-the-integrationgateway`, the same as the brief.

## Findings that adjust the brief

- **The check-in upsert is already in `CheckInService`.** `IntegrationGateway::setCheckIn()`
  (`IntegrationGateway.php:246-251`) only calls `CheckInService::setBuiltInValue()`.
  The `UniqueConstraintViolationException` handling is already in
  `CheckInService::createValue()`/`mirrorToCheckInTable()`/`createEmptyDay()` (`CheckInService.php:160-206`).
  So brief step 3's "check-in upsert → CheckInService" needs no code move. The
  check-in widget calls `CheckInService`/`CheckInTrackerService` directly in TASK-6.
  The concurrency case is already covered by `WidgetApiTest.php:762`.
- **Not all 13 bot tests mock the gateway.** 3 are `ConversationState` tests, 2
  connect-flow tests pass an unused mock, 7 mock it in earnest, and
  `SolytoBotTest.php:209` already uses the real gateway with real calendar events.
- **Bot and widget due-todo queries differ in more than timezone.** The bot
  (`dueTodos()`, `:58-66`) has no order and no limit. The widget
  (`dueTodosInUserTimezone()`, `:85-95`) orders by `due_at` and takes a limit.
  `due_at` is a `date` cast/column, so `where('due_at','<=',today())` and
  `whereDate('due_at','<=',$date)` return the same rows. A single `TodoService`
  method must keep the bot's unordered result, because the brief allows no bot
  message changes. See TASK-5.
- **Small guards stay in the adapter.** The gateway's no-op guards in
  `completeTodo()` (already completed) and `stopTimeTracking()` (already
  stopped) are widget-specific. Moving them into `TodoService::update` or
  `TimeTrackingService::stopTimer` would change REST behaviour, so they move
  into `WidgetService` unchanged.
- `SolytoBot::commitWithType()` (`SolytoBot.php:228-238`) is an unused private
  method that calls the gateway. Switch it like the rest. Removing it is out of
  scope; list it as a follow-up in `implementation.md`.

## Task breakdown

TASK-1: Rewrite STANDARDS §12.8 so domain modules still never call each other's services, while entry points and aggregators (`app/Bots`, `app/Mcp`, `app/Api/Widgets`) may call module services directly, like controllers. Shared logic lives in the owning service or in a common `app/Shared` service such as `QuickAddService`. Record the owner decision of 2026-11-21 under `## Decisions`.
  files: docs/STANDARDS.md (§12.8 at :854-861, `## Decisions`)
  depends: none
  risk: low — docs only. Leave §4.2/§10.2 and the historical correction note at :1151 to TASK-9, while the gateway still exists.

TASK-2: Add bot tests with real records, no gateway mocks, and check the reply text. They must pass against the current code.
  - `/todos` lists an open todo and leaves out another user's todo
  - `/day` with `Carbon::setTestNow` and a UTC user lists a todo due today and an overdue one, and leaves out a future todo and a completed one. Use UTC so the known timezone bug (`bot-day-timezone.md`) doesn't affect the result.
  - `/day` replies with the `EMPTY_DAY` text when nothing is due
  - quick-add auto-commit (high-confidence link) creates the link and replies with `ADDED_LINK`
  - quick-add type selection (`Todo` after `QUICK_ADD_AWAITING_TYPE`) creates the todo and replies with `ADDED_TODO`

  Build the bot through the container (`app(SolytoBot::class)`), so TASK-7 can change its constructor without editing these tests.
  files: app/Bots/Tests/SolytoBotTest.php
  depends: none
  risk: medium — quick-add link creation may make outbound HTTP calls (metadata, imgproxy). These must stay under `Http::fake()` and must not need Docker secrets. Check this before relying on the link path.

TASK-3: Move the running entry, projects by last use and the exclusive start ("switch") into `TimeTrackingService` as user-argument methods. Suggested names: `runningEntry(User)`, `projectsByLastUse(User, int $limit, ?string $exceptProjectId)`, `switchTo(User, TimeTrackingProject)`. The gateway methods become one-line delegations. Keep `DB::transaction(..., 3)`, `lockForUpdate()` and the NULL-ordering comment exactly as they are. Add service tests: the running entry, project order including never-used projects, switching stops other timers, and switching to the already running project is a no-op.
  files: app/Api/TimeTracking/Services/TimeTrackingService.php, app/Shared/Services/IntegrationGateway.php, app/Api/TimeTracking/Tests/TimeTrackingTest.php (or a new service test file in that folder, per docs/TESTING.md)
  depends: none
  risk: medium — concurrency-sensitive code. The cache forget inside the uncommitted transaction (`cache-followups.md` item 2) moves as-is. Fixing it is out of scope unless the owner asks.

TASK-4: (No-op confirmation.) Make sure the check-in widget path needs nothing new in `CheckInService`: `setBuiltInValue`, `builtInValuesOn` and `CheckInTrackerService::enabledBuiltInKeys` already exist. Merged into TASK-6, so there is no separate commit.
  files: none
  depends: none
  risk: low — the logic is already in the service.

TASK-5: Add one due-todos method to `TodoService`, e.g. `due(User $user, string $date, ?int $limit = null)`: not completed, `whereDate('due_at', '<=', $date)`, and ordered by `due_at` plus limited only when a limit is given. Then `dueTodos()` (with `today()->toDateString()`) and `dueTodosInUserTimezone()` (with the user's date and the limit) become one-line delegations with unchanged results. Add service tests for the date boundary, completed exclusion, user scoping, and the limit with ordering.
  files: app/Api/Todos/Services/TodoService.php, app/Shared/Services/IntegrationGateway.php, app/Api/Todos/Tests/TodoTest.php
  depends: none
  risk: low — read-only query, covered by the TASK-2 bot tests and WidgetApiTest. The conditional ordering is the one design choice, noted under Open questions.

TASK-6: Switch `WidgetService` from the gateway to the module services, all injected through the constructor: `CalendarService` (scoped, `app/Shared/Providers/AppServiceProvider.php:33`), `TodoService`, `TimeTrackingService`, `CheckInService`, `CheckInTrackerService`. Pass `$user` per call and drop `setUser()`. Keep the already-completed and already-stopped guards in `WidgetService`. `unavailableCalendars()`/`credentialsRejectedCalendars()` must read the same injected `CalendarService` instance that ran `listWidgetEvents()`. All 51 tests in `WidgetApiTest.php` must pass unchanged.
  files: app/Api/Widgets/Services/WidgetService.php
  depends: TASK-1, TASK-3, TASK-5
  risk: medium — the scoped `CalendarService` state shared across the request is easy to break. The widget calendar-unavailable tests are the guard.

TASK-7: Switch `SolytoBot` to inject `QuickAddService`, `TodoService` and `CalendarService`, and keep the authenticated `User` on the bot (set in `auth()`). Mapping: `detect` → `QuickAddService::detect`, `commit` → `QuickAddService::commit($user, …)`, `todos` → `TodoService::list`, `dueTodos` → `TodoService::due($user, today()->toDateString())`, `todayAppointments` → `collect(CalendarService::listTodayEvents($user))`. Replace the gateway-mock tests: delete them where the TASK-2 tests cover them, and for the error-path test (`commit` throws → default error reply) mock `QuickAddService` instead. The TASK-2 tests and the existing calendar `/day` test must pass unchanged, and no reply text may change.
  files: app/Bots/SolytoBot.php, app/Bots/Tests/SolytoBotTest.php
  depends: TASK-1, TASK-2, TASK-5
  risk: medium — the bot's constructor changes. The connect-flow tests build it with `new SolytoBot(mock)`, so they must move to `app(SolytoBot::class)`.

TASK-8: Delete `IntegrationGateway` and `IntegrationAuthException`, once `grep -rn 'IntegrationGateway\|IntegrationAuthException' app tests` returns nothing.
  files: app/Shared/Services/IntegrationGateway.php, app/Shared/Exceptions/IntegrationAuthException.php
  depends: TASK-6, TASK-7
  risk: low — mechanical once the grep is empty, and PHPStan catches stragglers.

TASK-9: Update the docs that still name the gateway. Leave `docs/reports/*` alone; they are dated, historical records.
  - STANDARDS §4.2 (:400-404): the late-resolution exception now covers only `QuickAddService`
  - §10.2 (:724): drop the `@throws IntegrationAuthException` example
  - §12.8: remove any remaining gateway wording
  - the historical correction note at :1151: leave it, or mark it superseded
  - `docs/AGENTS.md:45`: list only `QuickAddService`
  - `docs/backlog/bot-day-timezone.md`: point at `TodoService::due()` and the bot's call site
  - `docs/backlog/cache-followups.md` item 2: point at the new `TimeTrackingService` method

  `docs/TESTING.md` doesn't mention the gateway, so it needs no change.
  files: docs/STANDARDS.md, docs/AGENTS.md, docs/backlog/bot-day-timezone.md, docs/backlog/cache-followups.md
  depends: TASK-8
  risk: low — docs only.

## Order / commits

TASK-1 → TASK-2 → TASK-3 → TASK-5 → TASK-6 → TASK-7 → TASK-8 → TASK-9. Make one commit each. TASK-4 has no commit. Run `composer test` and `composer analyse` green after every commit.

## Open questions (non-blocking, defaults chosen)

- **Conditional ordering in `TodoService::due()`.** This keeps the bot's reply order unchanged. The alternative is to always order by `due_at`, which is cleaner but may reorder `/day` lines. Default: conditional ordering. The `bot-day-timezone.md` fix can unify the two later.
- **Cache forget inside the transaction** (`cache-followups.md` #2). The backlog says to fix it "when touching that code", but the brief says behaviour must not change. Default: move it as-is and leave the backlog entry open.
