# Tasks: check-in widget: data endpoint and set-value action

id: speech
analyst: analyst
date: 2026-10-29

<!-- Produced by @analyst from brief.md. -->

## Context (read before starting)

- Token gate needs no change: `widget`-ability tokens pass on any route
  **named** `widgets.*` (`AppServiceProvider`). Both new routes go inside the
  existing `Route::prefix('widgets')->name('widgets.')` group in
  `routes/api.php:257` (throttle `widgets` comes with it).
- STANDARDS 12.8: Widgets must not call `CheckInService` directly. Add
  `IntegrationGateway` methods (`checkAuth()` first, service resolved late via
  `app(CheckInService::class)`), like `completeTodo()` / `startTimeTracking()`.
  Importing the `CheckIn` model is fine.
- `WidgetService` already has the pieces to reuse: locale/timezone resolution,
  `withoutNulls()`, the filter-empty-sections pattern, `SCHEMA_VERSION`.
  `yourDay()` shows `refresh_at` = next local midnight. Do not refactor
  `yourDay()` / `timeTracking()`.
- Default trackers/sports live as **private** constants in
  `CheckInSettingsResource` (`DEFAULT_TRACKERS`, `DEFAULT_SPORTS`). The widget
  must use the same defaults; do not change that resource's behaviour or the
  app's check-in endpoints (out of scope).
- `CheckInService::find($user, 'Y-m-d')` matches `date = 'Y-m-d 00:00:00'`;
  `create()` / `update()` clear the user's `checkin` cache. Use them, like
  `CheckInController::store`.
- `StoreCheckInRequest` uses `in:1,…`, which accepts strings like `"3"`. The
  widget needs `value` to be `present|nullable|integer|between:1,5` (sports
  `1,6`) so a string like `"abc"` is 422.
- `CheckInFactory` generates `sports` 0–120 (outside the 1–6 domain): tests
  must set `sports` explicitly.
- Sport id mapping (value ↔ key): 1 dumbbell, 2 bike, 3 mountain,
  4 footprints, 5 waves_ladder, 6 yoga.
- No migration, no new dependency, no DAV/`pgsql` change is needed.

## Task breakdown

TASK-1: Add the `en` strings for the check-in widget (title, empty = "no trackers enabled", the 12 tracker names) and hand de/fr/es/it to the `translator` agent per `docs/I18N.md`.
  files: resources/lang/en/widgets.php, resources/lang/{de,fr,es,it}/widgets.php (translator)
  depends: none
  risk: low — additive keys; watch that the existing `section_today` ("Events") is not reused for the check-in section title, and that the developer does not translate on the fly.

TASK-2: Add `IntegrationGateway` methods for the widget: the user's check-in for a given local date (`?CheckIn`), and a set-tracker method that finds today's check-in and calls `CheckInService::update` or `create` with `[tracker => value]` (plus `date` on create).
  files: app/Shared/Services/IntegrationGateway.php
  depends: none
  risk: medium — must always scope by the gateway user (`find` uses `forUser`), so another user's check-in can never be touched; setting the same value again must not fail (a plain update is fine).

TASK-3: Add `WidgetService::checkIn(User)` building the payload from brief step 1 (kind `check-in`, schema_version 1, generated_at, `refresh_at` = next local midnight, labels, one `today` section with rows for the enabled trackers in the app's fixed order, left out when none enabled), with option/colour building for mood, sports (selected sports in order plus today's stored sport appended when not among them) and the other trackers; plus a thin `setCheckIn(User, string $tracker, ?int $value)` wrapper that computes today in the user's timezone and calls TASK-2.
  files: app/Api/Widgets/Services/WidgetService.php
  depends: TASK-1, TASK-2
  risk: medium — rows must follow the app order, not the stored `enabled_trackers` order; unknown tracker keys or sport keys in settings must be skipped rather than crash; `action.value` must be emitted as `null` (do not run the action through `withoutNulls()`); values should be cast to int.

TASK-4: Add a `CheckInWidgetResource` with an `@OA\Schema` (`CheckInWidget`) documenting the format: `refresh_at`, section key `today`, row id = tracker key, route key `check_in`, and the new `scale` action (`key` `check_in.set`, nullable `value`, `options[]` of `{value, icon, color}`).
  files: app/Api/Widgets/Resources/CheckInWidgetResource.php (new)
  depends: TASK-3
  risk: low — documentation plus array pass-through, same shape as `TimeTrackingWidgetResource`.

TASK-5: Add the request, controller actions and routes: `GET check-in` (`check-in`) and `POST actions/check_in.set/{tracker}` (`actions.check-in-set`) with `->whereIn('tracker', [12 keys])`; a FormRequest validating `value` as `present|nullable|integer|between:1,5` (`1,6` when the route tracker is `sports`); success envelope with `null` data; OpenAPI `@OA\Get`/`@OA\Post` with `@OA\RequestBody` (`value` integer nullable), `tracker` path enum, and 401/404/422/426/429 responses.
  files: app/Api/Widgets/Controllers/WidgetController.php, app/Api/Widgets/Requests/SetCheckInRequest.php (new), routes/api.php
  depends: TASK-3, TASK-4
  risk: medium — the route name must stay under `widgets.*` or the widget token gets 401; the `.` in `check_in.set` sits in a literal segment (fine, as with `todo.complete`); `value` must be `present` so a missing body is 422, not a silent clear.

TASK-6: Add Pest tests per brief step 4 (rows/order/defaults/values/null; mood, sports incl. stale stored sport, other options and colours; user timezone for "today" and `refresh_at`; set creates/updates/clears, 422 for 6 on mood, 7 on sports and a string, 404 unknown tracker, widget token works, other user untouched; widget token 401 on `/check-in`).
  files: app/Api/Widgets/Tests/WidgetApiTest.php (or a new app/Api/Widgets/Tests/CheckInWidgetApiTest.php reusing its helpers)
  depends: TASK-5
  risk: medium — use `Carbon::setTestNow` with a time where UTC and the user's timezone fall on different dates to prove the timezone rule; set `sports` explicitly in factories.

TASK-7: Run `composer test` and `composer analyse` and fix anything the change broke.
  files: whatever TASK-1..6 touched
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5, TASK-6
  risk: low — the project's required gate before the job is done.

## Open points (not blocking)

- "Reusing the app's existing check-in names": those strings live in the
  separate frontend repo, not here. The developer writes plain `en` names
  (Mood, Sleep, Dreams, Food quality, Food amount, Water, Sports, Work,
  Menstruation, Alcohol, Smoking, Social life); a human may want to align
  the wording with the frontend afterwards.
- A stored `sports` value outside 1–6 (legacy data) is assumed to add no
  extra option; `action.value` still shows the stored value.
- `value` validation is read as strict `integer` (JSON number); a numeric
  string like `"3"` is accepted by Laravel's `integer` rule. Only non-numeric
  strings are guaranteed 422, which matches the brief's test ("a string").
- The brief's sketch shows the `today` section without a `title`. The
  existing widgets give every section one, and `section_today` already means
  "Events" (Your Day). Either leave `title` out of this section or add a
  check-in-specific key; don't reuse `section_today`.
- Default trackers: the widget needs the same defaults as
  `CheckInSettingsResource`, whose constants are private. Simplest is to make
  those constants public and reference them (no behaviour change); duplicating
  them is the fallback.
