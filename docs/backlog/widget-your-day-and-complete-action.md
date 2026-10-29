# Backlog: widget endpoints: Your Day data and the complete-todo action

created: 2026-10-29

## What

Add the first widget routes, usable with the widget token from
[[widget-token]]:

- `GET /v1/widgets/your-day`: the Android "Your Day" widget's data, in the
  generic, display-ready widget format.
- `POST /v1/widgets/actions/todo.complete/{todo}`: completes a todo from the
  widget.

This is job 3 of 6 in `solyto/mobile`
`docs/reports/android-homescreen-widgets-decisions.md` (D2, D8, D11). Read
that document first. It depends on [[widget-token]].

## Why

The server already has the user's timezone, language and formats, so it
builds the widget data and the native widget only renders it. Every widget
shares one format, so a new widget is mostly a new endpoint plus a native
layout. The action endpoint makes tap-to-complete work and proves the action
plumbing that later widgets reuse.

## Steps

1. **Routes:** a `widgets` prefix group with route names `widgets.*`,
   behind `auth:sanctum` and `TrackApiTraffic` like the main group, plus a
   `widgets` rate limiter keyed by token id (for example 60 per minute). The
   names are what the gate from [[widget-token]] checks, so every route in
   the group must be named.
2. **Domain:** a new `app/Api/Widgets/` domain (controller, service,
   resources, tests), following `docs/STANDARDS.md`.
3. **`GET /widgets/your-day`** returns, inside the usual `ApiResponse`
   envelope:

   ```
   {
     kind: "your-day", schema_version: 1, generated_at,
     refresh_at,                 // next local midnight in the user's timezone, ISO with offset
     labels: { title, empty },   // translated
     sections: [{ key, title, rows: [{
       id, title, subtitle?, time_label?,
       at?, until?, all_day,     // ISO timestamps, or Y-m-d dates when all_day
       icon?,                    // "event" | "todo"
       route: { key, id? },
       action?: { type, key, checked? }
     }] }]
   }
   ```

   Sections, in this order (leave out empty ones; `labels.empty` covers "all
   empty"):
   - `today`: today's events in the user's timezone, sorted by start
     (all-day first). From `CalendarService::listWidgetEvents()` (accepted
     calendars only, recurring events expanded, existing cache).
   - `due`: the user's todos that are not completed with `due_at` today or
     earlier (user's timezone), oldest first. Same rule as the app's
     `TodoFilterService` `today-overdue`. `due_at` is a date, so send it as a
     date with `all_day: true`. Each row gets
     `action: { type: "check", key: "todo.complete", checked: false }`.
     Overdue rows say so in `subtitle` (for example "Overdue · 27 Oct").
   - `upcoming`: the remaining events of the `listWidgetEvents()` range
     (after today), sorted by start.

   Display text (`time_label`, `subtitle`, section titles) follows the
   user's language, timezone, `time_format` and `date_format` settings.

   Route keys (the app maps them to paths; keep exactly these):
   `todo` with the todo id for todo rows, `calendar` without id for event
   rows.

   Cap each section at 20 rows.
4. **`POST /widgets/actions/todo.complete/{todo}`** (route name for example
   `widgets.actions.todo-complete`):
   - 404 via model binding when the todo does not exist; 403 when
     `isResourceOwner()` fails, like `TodoController::update`;
   - already completed: success, nothing changes (**idempotent**);
   - otherwise `TodoService::update($todo, ['is_completed' => true])`, so
     recurrence (next occurrence) and cache invalidation work as in the app;
   - success response with no payload; the widget re-fetches.
   There is no "uncomplete" action.
5. **Translations:** a new `resources/lang/{en,de,fr,es,it}/widgets.php` for
   labels, section titles and subtitles. New strings go to the `translator`
   agent.
6. **OpenAPI** annotations for both endpoints, including the format above.
7. **Tests:**
   - section content and order; a todo due tomorrow, a completed todo and an
     event tomorrow+4 do not appear; overdue todos appear with their subtitle;
   - "today" follows the user's timezone, not the server's (a user in
     `Pacific/Auckland` and one in `America/Los_Angeles`);
   - all-day events and todos come as dates; timed events as timestamps;
   - `refresh_at` is the next local midnight;
   - labels follow the user's language; times follow 12/24-hour setting;
   - the endpoints work with a widget token and with a normal token;
   - completing: owner succeeds, other user 403, missing 404, already
     completed is a no-op success, a recurring todo gets its next
     occurrence;
   - widget token still gets 401 on non-widget routes (the gate, now with
     real routes).

## Out of scope

- Any change to the app's dashboard Your Day; this endpoint is used only by
  the widget for now.
- Scored todos (their scoring only exists in TypeScript).
- Other widgets and other actions.

## Notes

- Format versioning: additive changes keep `schema_version`; a breaking
  change bumps it. Native ignores unknown fields and types.
- Error contract the widget relies on (D11): 401 token invalid, 404 on an
  action means the item is gone, 426 from `RecordClientVersion`, 429 from
  the limiter. Do not return other statuses for these cases.
