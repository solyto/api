# Tasks: widget endpoints: Your Day data and the complete-todo action

id: whisper
analyst: analyst
date: 2026-10-29

## Context checked

- Branch is `feature/whisper_widget-endpoints-your-day-data-and-the-complete-todo-action`, which matches the brief.
- The [[widget-token]] job (`birthday`) is merged. The gate in `AppServiceProvider::boot()` lets a token without `*` through only on routes named `widgets.*`. `WidgetTokenTest` currently checks this with a temporary `widgets.probe` route.
- `CalendarService::listWidgetEvents()` exists. Its window is today 00:00 to today+3 days in the user's timezone, accepted calendars only, and it is cached per day.
- `TodoService::update()` already sets `completed_at`, spawns the next occurrence only when the todo goes from not completed to completed, and forgets the cache.
- **This repo has no `TodoFilterService`**; it probably lives in the frontend. The rule from the brief is used as stated: `is_completed = false AND due_at <= today (user's timezone)`. `IntegrationGateway::dueTodos()` uses the server's `today()`, so the widget can't reuse it as is.
- STANDARDS 12.8: modules don't call another module's service. Widgets reach todos and calendars through `App\Shared\Services\IntegrationGateway`. The brief calls this "a new external entry point", which means new gateway methods.
- STANDARDS 1.4: routes get a name only when something calls them by name. Here the gate is that caller, so the `widgets.` names are allowed. The text of 1.4 says "eleven named groups … no other names" and will be out of date afterwards (see TASK-11).
- `OpenApiSpecTest` fails if an annotated path doesn't match a route, so annotations and routes must agree.

## Task breakdown

TASK-1: Add a `widgets` rate limiter (60/min) keyed by the current access token id, falling back to user id and then ip.
  files: app/Shared/Providers/AppServiceProvider.php
  depends: none
  risk: low — adds a new limiter next to the existing ones and doesn't change any of them.

TASK-2: Add `IntegrationGateway` methods for the widget: `widgetEvents()` (wraps `CalendarService::listWidgetEvents()`), `dueTodosInUserTimezone()` (or a similar name: not completed, `due_at <= today` in the user's timezone, oldest first, limit 20), and `completeTodo(Todo $todo)` (no-op when already completed, otherwise `TodoService::update($todo, ['is_completed' => true])`). All three use `checkAuth()`.
  files: app/Shared/Services/IntegrationGateway.php
  depends: none
  risk: low — only new methods. Leave `dueTodos()`, which the bot uses, unchanged.

TASK-3: Add `resources/lang/en/widgets.php` with the English strings: `labels.title`, `labels.empty`, section titles (`today`, `due`, `upcoming`), the overdue subtitle (for example `Overdue · :date`), the all-day time label, and any 12h/24h or date words the formatter needs. Then give de/fr/es/it to the `translator` agent.
  files: resources/lang/{en,de,fr,es,it}/widgets.php
  depends: none
  risk: low — new files. Only the de/fr/es/it translations are delegated.

TASK-4: Create `app/Api/Widgets/Services/YourDayService` that builds the your-day payload for a `User` from the gateway: `kind`, `schema_version: 1`, `generated_at`, `refresh_at` (next local midnight, ISO with offset), translated `labels`, and the sections `today` / `due` / `upcoming` in that order. Empty sections are left out, each section has at most 20 rows, and rows follow the brief's format (dates for all-day events and todos, ISO timestamps for timed events, `icon`, `route {key: todo, id}` / `{key: calendar}`, the `todo.complete` check action on due rows, and an overdue subtitle).
  files: app/Api/Widgets/Services/YourDayService.php (new)
  depends: TASK-2, TASK-3
  risk: medium — timezone edge cases: all-day events whose dates must not shift, multi-day or ongoing events (see open question 3), and splitting `today` from `upcoming` at local midnight.

TASK-5: Format display text in the user's language, timezone, `time_format` and `date_format`: `time_label` for timed events, the date in the overdue subtitle, and upcoming dates. Use `__()` with an explicit locale, the user's language, `config('app.locales')`, and Carbon `locale()`/`translatedFormat`.
  files: app/Api/Widgets/Services/YourDayService.php (or a private helper inside it, no new abstraction)
  depends: TASK-4
  risk: medium — the stored values of `time_format` and `date_format` are unclear (see open question 1).

TASK-6: Create resource(s) for the your-day payload with `@OA\Schema` covering the full format (rows, route, action). Follow STANDARDS 6.2.
  files: app/Api/Widgets/Resources/YourDayResource.php (new; add a row resource only if it keeps the schema readable)
  depends: TASK-4
  risk: low — no logic, but the schema has to match `toArray` exactly.

TASK-7: Create `app/Api/Widgets/Controllers/WidgetController` with `yourDay()` and `completeTodo(Request, Todo $todo)`. `completeTodo` returns 403 through `isResourceOwner()` (like `TodoController::update`), calls the gateway, and returns success with `data: null`. Add OpenAPI annotations for both (operationIds such as `widgetsYourDay` / `widgetsTodoComplete`; responses 200/401/403/404/426/429; paths relative to `/api/v1`).
  files: app/Api/Widgets/Controllers/WidgetController.php (new)
  depends: TASK-2, TASK-6
  risk: low — follows the existing controller pattern (STANDARDS 1.1, 2.x).

TASK-8: Register the routes as a `Route::prefix('widgets')->name('widgets.')->middleware('throttle:widgets')` block inside the existing `auth:sanctum` + `TrackApiTraffic` group: `GET your-day` → `widgets.your-day`, `POST actions/todo.complete/{todo}` → `widgets.actions.todo-complete`. Every route gets a name.
  files: routes/api.php
  depends: TASK-1, TASK-7
  risk: medium — the gate depends on these names, and an unnamed route in the group would 401 for widget tokens. Also check that `{todo}` binding and the dot in `todo.complete` resolve as expected.

TASK-9: Write the feature tests in `app/Api/Widgets/Tests/` for everything in brief step 7: section content and order, exclusions, the overdue subtitle, Auckland vs Los Angeles "today" (with `Carbon::setTestNow`), dates vs timestamps, `refresh_at`, language and 12/24h, widget token and normal token on both endpoints, complete (owner / 403 / 404 / idempotent / recurring gets its next occurrence), and widget token 401 on non-widget routes.
  files: app/Api/Widgets/Tests/WidgetYourDayTest.php, app/Api/Widgets/Tests/WidgetTodoCompleteTest.php (new)
  depends: TASK-8, TASK-5
  risk: medium — creating DAV events in SQLite tests should follow the existing approach (`CommandTest`/`CalendarApiTest` create events through the API). The global helper functions in `WidgetTokenTest.php` (`bearer`, `sessionToken`, …) must not be declared again under the same names, or the full suite fatals.

TASK-10: Optionally switch `WidgetTokenTest`'s "accepted on widgets.*" case from the probe route to the real `widgets.your-day` route. Only do this if it is a direct replacement; otherwise leave it.
  files: app/Api/Users/Tests/WidgetTokenTest.php
  depends: TASK-8
  risk: low — test-only, and optional.

TASK-11: Update STANDARDS 1.4 so it counts the `widgets.` group as a named group whose caller is the token gate, and drop the "no other names" count. Only change that sentence.
  files: docs/STANDARDS.md
  depends: TASK-8
  risk: low — a doc change, but STANDARDS edits follow `docs/STANDARDS_WORKFLOW.md`. If that workflow needs owner sign-off, record it as a follow-up instead.

TASK-12: Run `composer test` and `composer analyse`; both must exit 0.
  files: none
  depends: all
  risk: low — verification only.

## Open questions / assumptions for the developer

1. **`time_format` / `date_format` values are not defined in this repo.** The migration defaults are `H:i` and `dd.mm.YYYY`, the factory uses `24h|12h` and `Y-m-d|d/m/Y|m/d/Y`, and the tests use `H:i` and `d.m.Y`. These values come from the frontend, which isn't in this repo. Check the frontend or the mobile decisions doc for the real values. If neither is available, use 12-hour only for clearly 12h values (`12h`, or values containing `A`/`a`/`g`/`h`) and 24-hour otherwise. Map the date format to day/month order for short dates. Write the mapping down in implementation.md.
2. **Event row `id`:** `EventDTO::$id` is the same for every occurrence of a recurring event. A stable unique id could be `uri` + occurrence start. The brief doesn't say, so pick one and document it.
3. **Multi-day or ongoing events** that started before today: put them in `today` when they overlap today, the same way the reminder and `/day` do. They should not appear again in `upcoming`.
4. The mobile repo's `docs/reports/android-homescreen-widgets-decisions.md` (D2, D8, D11) can't be read from this worktree. These tasks follow the brief, which quotes the parts that matter.
5. A missing `user->settings` falls back to UTC and `en`, the same as `listWidgetEvents()`.
