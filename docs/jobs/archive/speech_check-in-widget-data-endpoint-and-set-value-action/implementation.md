# Implementation: check-in widget: data endpoint and set-value action

id: speech
developer: developer
date: 2026-10-29

## Summary

Added `GET /api/v1/widgets/check-in` (`widgets.check-in`) and
`POST /api/v1/widgets/actions/check_in.set/{tracker}`
(`widgets.actions.check-in-set`) inside the existing `widgets.*` group, so the
widget token, throttle, envelope and error contract are inherited. The format
gains the `scale` action (`value` + `options[{value, icon, color}]`), posted
with a JSON body `{ "value": int|null }`. `composer test` and
`composer analyse` both exit 0.

## Changes

TASK-1: `resources/lang/{en,de,fr,es,it}/widgets.php`: `check_in_title`,
`check_in_empty` and `tracker_<key>` for the 12 trackers. No subagent tool
was available in this session, so I wrote de/fr/es/it myself following
`docs/I18N.md` and the translator agent's rules (Check-in kept as in the
glossary). The key check prints nothing.

TASK-2: `app/Shared/Services/IntegrationGateway.php`: `checkInOn($date)` and
`setCheckIn($date, $tracker, $value)`, both `checkAuth()` first and scoped to
the gateway user through `CheckInService::find`, then `update`/`create`.
Clearing (`null`) when the day has no check-in is a no-op and does not
create an empty row (that row would otherwise show up as a check-in mark in
the calendar).

TASK-3: `app/Api/Widgets/Services/WidgetService.php`: `checkIn(User)` and
`setCheckIn(User, tracker, value)`. Rows follow the fixed app order
(`CHECK_IN_TRACKERS`) filtered by `enabled_trackers`. Unknown tracker/sport
keys are skipped. Values are cast to int, and non-numeric values become
`null`. `action.value` is always present. Options and colors follow the
brief. Sports are the selected sports in order plus today's stored sport
(1–6) when it isn't selected. The section has no `title` (brief sketch;
`section_today` means "Events"). I made the defaults in
`CheckInSettingsResource` public constants so both use the same defaults,
with no behaviour change.

TASK-4: `app/Api/Widgets/Resources/CheckInWidgetResource.php` (new) with the
`CheckInWidget` OpenAPI schema documenting `refresh_at`, the row shape and
the `scale` action.

TASK-5: `app/Api/Widgets/Requests/SetCheckInRequest.php` (new,
`present|nullable|integer|between:1,5`, `1,6` for sports),
`WidgetController::checkIn` / `setCheckIn` with `@OA\Get` / `@OA\Post`
(path enum, request body, 401/404/422/426/429), and the routes in
`routes/api.php` with `whereIn('tracker', WidgetService::CHECK_IN_TRACKERS)`.

TASK-6: `app/Api/Widgets/Tests/WidgetApiTest.php`: rows/order/defaults/
values/null, empty section, mood/other/menstruation options and colors,
sports incl. a stored sport that is no longer selected, user timezone for
today and `refresh_at` (Auckland / Los Angeles), language. For set: creates,
updates (and is idempotent), clears, no row created on clearing without one,
422 for (6 mood, 0 water, 7 sports, string, missing value), 404 for an
unknown tracker, another user untouched. Also covered: widget and normal
tokens both work, and the widget token gets 401 on `/check-in`.

TASK-7: `composer test` and `composer analyse` both pass. Fixed one PHPStan
finding (nullsafe on settings).

## Known issues / follow-ups

- de/fr/es/it strings were written by the developer following the
  translator rules, not by a separate translator run; the owner may want to
  review them. The tracker names may also need aligning with the frontend's
  wording (that repo is not available here).
- The very first `composer test` run (right after `composer install` and
  creating the missing `storage/framework` dirs) reported 2 failures that I
  couldn't identify. Three later full runs all passed with 0 failures, so it
  may be a flaky or cold-start test. Worth checking if it shows up again.
- `.env.testing` shows as modified in `git status` because it is a character
  device in this sandbox. I didn't touch it and it isn't committed.
