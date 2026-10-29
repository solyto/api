# Verdict: check-in widget: data endpoint and set-value action

id: speech
reviewer: reviewer
date: 2026-10-29

## Review

Verified on branch `feature/speech_check-in-widget-data-endpoint-and-set-value-action`
against `main...HEAD`. `composer test` exits 0 (no failures; the warnings
come from the sandbox missing `/workspace/.env` and appear on every test, not
only this job's). `composer analyse` reports no errors.

TASK-1: PASS
notes: en strings added; `section_today` is not reused. de/fr/es/it were
written by the developer, not by the translator agent, because no subagent
tool was available. They read correctly and every key is present in all five
locales. The owner may want a translator pass, and may want to align the
tracker names with the frontend wording. This is not a blocker.

TASK-2: PASS
notes: `IntegrationGateway::checkInOn` / `setCheckIn` call `checkAuth()`
first, resolve `CheckInService` late, and scope to the gateway user through
`find` (`forUser`). They use `update`/`create`, so the cache is cleared.
Clearing (`null`) a day with no check-in now creates no row. That is a small
departure from "creates or updates" in the brief, but it is documented, it is
the sensible behaviour (an empty row would show as a calendar mark), and it is
tested.

TASK-3: PASS
notes: `WidgetService::checkIn` gives rows in the fixed app order, filtered by
`enabled_trackers`, with the same defaults as `CheckInSettingsResource`
(those constants are now public, as the tasks' open point allows, with no
behaviour change). `action.value` is always present and cast to int or
`null`. Options, icons and colours match the brief. The sports option list
appends a stored sport (1–6) that is no longer selected, and skips unknown
keys. The section is left out when no tracker is enabled. `refresh_at` is the
next local midnight. A user without settings is handled (`??` on a null
settings relation).

TASK-4: PASS
notes: `CheckInWidgetResource` with a `CheckInWidget` schema that documents
the `scale` action, its nullable `value` and `options[]`.

TASK-5: PASS
notes: Both routes are inside the `widgets.` group with the right names, and
`whereIn('tracker', CHECK_IN_TRACKERS)` returns 404 for unknown trackers.
`SetCheckInRequest` is `present|nullable|integer|between:1,5|6`. OpenAPI
covers the path enum, the request body and 401/404/422/426/429.

TASK-6: PASS
notes: Every test item from brief step 4 is covered. Timezone tests use
Auckland and Los Angeles with dates on both sides of UTC. Factories set
`sports` explicitly. There is also a test that the widget token gets 401 on
`/check-in`.

TASK-7: PASS
notes: Both gates were run again by the reviewer and are green.

Scope: the only change outside the planned files is the `private` → `public`
constants in `CheckInSettingsResource`, which the tasks' open points
anticipated. No migration, dependency or DAV change.

## Security

Not run separately. Every write is scoped to the authenticated user through
the gateway, and a test confirms another user's check-in is never touched.

## Overall

APPROVED

No blockers.
