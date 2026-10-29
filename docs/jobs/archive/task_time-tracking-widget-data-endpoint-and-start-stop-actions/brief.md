# Brief: time tracking widget: data endpoint and start/stop actions

type: feature
id: task
branch: feature/task_time-tracking-widget-data-endpoint-and-start-stop-actions
date: 2026-10-29
author: Leander Muskalla

## What

Add the widget routes for an Android time tracking widget, in the existing
`widgets.*` group (usable with the widget token):

- `GET /api/v1/widgets/time-tracking`: the running timer and the projects to
  start, in the generic widget format.
- `POST /api/v1/widgets/actions/time_tracking.start/{project}`: start a timer
  for a project, switching from a running one.
- `POST /api/v1/widgets/actions/time_tracking.stop/{entry}`: stop a timer.

This builds on [[widget-token]] and [[widget-your-day-and-complete-action]]
(both done): same token gate, same format, same envelope, same error
contract. The design is in `solyto/mobile`
`docs/reports/android-homescreen-widgets-decisions.md` (§6, time tracking
widget).

## Why

A user asked to start and stop time tracking from the home screen. The widget
shows what is running and starts a project with one tap.

## The format additions (backwards compatible, `schema_version` stays 1)

- Row field `live: "elapsed"`: the row's `at` is a start time and the widget
  shows the elapsed time, counting up by itself.
- Row field `color`: `#RRGGBB`, optional.
- Action type `button` with an `icon`: `"play"` or `"stop"`.
- `refresh_at` is optional; this widget leaves it out.

Old app versions ignore unknown fields and action types, as the format
requires.

## Steps

1. **`GET /widgets/time-tracking`** (route name `widgets.time-tracking`),
   inside the usual `ApiResponse` envelope:

   ```
   {
     kind: "time-tracking", schema_version: 1, generated_at,
     labels: { title, empty },             // translated; empty = "no projects yet"
     sections: [
       { key: "running", title, rows: [{   // only while a timer runs; one row
           id: <entry uuid>,
           title: <project title>,
           subtitle?: <entry description>,
           at: <started_at, ISO 8601 with offset>,
           live: "elapsed",
           all_day: false,
           color?: "#RRGGBB",
           icon: "timer",
           route: { key: "time_tracking_project", id: <project uuid> },
           action: { type: "button", key: "time_tracking.stop", icon: "stop" }
       }] },
       { key: "projects", title, rows: [{  // the user's projects
           id: <project uuid>,
           title: <project title>,
           all_day: false,
           color?: "#RRGGBB",
           route: { key: "time_tracking_project", id: <project uuid> },
           action: { type: "button", key: "time_tracking.start", icon: "play" }
       }] }
     ]
   }
   ```

   - `projects`: most recently used first (latest entry `started_at`), then
     projects without entries by title. At most 20. The running project is
     left out of `projects` (it is in `running`).
   - Leave out empty sections; no projects and no running timer means
     `sections: []` (the widget shows `labels.empty`).
   - `color` only when the stored value is a valid `#RRGGBB`; the column is
     free text (`max:7`).
   - Section titles and labels in the user's language
     (`resources/lang/{en,de,fr,es,it}/widgets.php`); new strings go to the
     translator.
2. **`time_tracking.start/{project}`** (route name
   `widgets.actions.time-tracking-start`):
   - 404 via model binding; 403 when the project is not the user's;
   - the project's timer is already running: success, nothing changes
     (idempotent);
   - another timer is running: stop it (`TimeTrackingService::stopTimer`) and
     start the project (`startTimer`, no description), **in one transaction
     that locks the user's running entries**, so two quick taps can never
     leave two running timers;
   - success response with no payload; the widget re-fetches.
   This deliberately differs from `POST /time-tracking/entries/start`, which
   answers 409 while a timer runs. The app's endpoint does not change.
3. **`time_tracking.stop/{entry}`** (route name
   `widgets.actions.time-tracking-stop`):
   - 404 via model binding; 403 when the entry is not the user's;
   - already stopped: success, nothing changes (idempotent);
   - otherwise `TimeTrackingService::stopTimer`;
   - success response with no payload.
4. **OpenAPI** annotations for all three routes, documenting the additions
   above in the widget schema.
5. **Tests:**
   - payload: running section only while a timer runs, with `at`,
     `live`, the stop action and the project's route; projects ordered by last
     use, capped at 20, without the running project; invalid colors left out;
     labels follow the user's language; works with a widget token and with a
     normal token;
   - start: starts when idle; switches from another running timer (the old
     one is stopped with its duration, the new one runs); a no-op for the
     running project; 403 for another user's project; 404 for a missing one;
     never two running timers;
   - stop: stops; a no-op when already stopped; 403 for another user's entry;
     404 for a missing one;
   - the widget token still gets 401 on `/time-tracking/*`.

## Out of scope

- Descriptions, categories or manual entries from the widget.
- NFC tags and Samsung Routines (the original request mentioned them).
- Any change to the app's own time tracking endpoints.

## Notes

- Error contract the widget relies on: 401 token invalid, 404 on an action
  means the item is gone, 426 from `RecordClientVersion`, 429 from the
  `widgets` limiter.
- Rate limits: the existing `widgets` limiter covers these routes.
