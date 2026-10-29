# Brief: check-in widget: data endpoint and set-value action

type: feature
id: speech
branch: feature/speech_check-in-widget-data-endpoint-and-set-value-action
date: 2026-10-29
author: Leander Muskalla

## What

Add the widget routes for an Android check-in widget, in the existing
`widgets.*` group (usable with the widget token):

- `GET /api/v1/widgets/check-in`: today's check-in for the user's enabled
  trackers, with the options to tap, in the generic widget format.
- `POST /api/v1/widgets/actions/check_in.set/{tracker}`: set one tracker of
  today's check-in to a value, or clear it.

This builds on the existing widget routes (`widget-token`,
`widget-your-day-and-complete-action`, `widget-time-tracking`): same token
gate, format, envelope and error contract. The design is in `solyto/mobile`
`docs/reports/android-homescreen-widgets-decisions.md` (§7, check-in widget).

## Why

Checking in daily is quicker from the home screen: one tap per tracker,
without opening the app.

## The format additions (backwards compatible, `schema_version` stays 1)

- Action type **`scale`**: `{ "type": "scale", "key", "value", "options" }`.
  `value` is the current value or `null`. `options` is the list of tappable
  values: `{ "value": <int>, "icon": "<key>", "color": "#RRGGBB" }`.
- **Actions with a body:** a `scale` action is posted with
  `Content-Type: application/json` and `{ "value": <int> }` or
  `{ "value": null }` (clear). Existing actions keep posting no body.

Old app versions ignore unknown action types, as the format requires.

## Steps

1. **`GET /widgets/check-in`** (route name `widgets.check-in`), inside the
   usual envelope:

   ```
   {
     kind: "check-in", schema_version: 1, generated_at,
     refresh_at,                        // next local midnight: the widget resets to the new day
     labels: { title, empty },          // translated; empty for "no trackers enabled"
     sections: [{ key: "today", rows: [{
       id: <tracker key>,               // e.g. "mood", "food_quality"
       title: <translated tracker name>,
       all_day: true,
       route: { key: "check_in" },
       action: {
         type: "scale", key: "check_in.set",
         value: <today's value or null>,
         options: [{ value, icon, color }]
       }
     }] }]
   }
   ```

   - **Rows:** the user's `enabled_trackers` (from `check_in_settings`, with
     the same defaults as `CheckInSettingsResource`), in the app's order:
     mood, sleep, dreams, food_quality, food_amount, water, sports, work,
     menstruation, alcohol, smoking, social_life.
   - **Today** means the user's timezone; values come from that day's
     check-in, or `null` when there is none.
   - **Options**, mirroring the app's daily check-in
     (`DailyCheckIn.svelte`, `DailyCheckInIcon.svelte`):
     - mood: values 1–5, icons `mood_1` … `mood_5` (angry, frown, meh,
       smile, laugh);
     - sports: the user's `selected_sports` in order, each with its sport id
       as value (dumbbell 1, bike 2, mountain 3, footprints 4, waves_ladder 5,
       yoga 6) and icon `sport_<id>` (e.g. `sport_dumbbell`); plus today's
       stored sport at the end when it is not among them (as the app does);
     - every other tracker: values 1–5, all with the tracker's own icon key
       (`sleep`, `dreams`, `food_quality`, `food_amount`, `water`, `work`,
       `menstruation`, `alcohol`, `smoking`, `social_life`).
   - **Colors**, as in the app: by value 1 `#EF4444`, 2 `#F97316`, 3
     `#3B82F6`, 4 `#86EFAC`, 5 `#22C55E` (Tailwind red-500, orange-500,
     blue-500, green-300, green-500, as in `DailyCheckInIcon.svelte`); sports
     all `#1DBDA5` (the app's primary color, `--color-s-teal`); menstruation
     all `#FB7185` (rose-400).
   - Tracker names and labels in the user's language
     (`resources/lang/{en,de,fr,es,it}/widgets.php`), reusing the app's
     existing check-in names. New strings go to the translator.
   - Leave the section out when no tracker is enabled.
2. **`POST /widgets/actions/check_in.set/{tracker}`** (route name
   `widgets.actions.check-in-set`), body `{ "value": int|null }`:
   - `{tracker}` must be one of the 12 tracker keys (route constraint):
     anything else is 404;
   - `value`: `null`, or 1–5 (sports: 1–6), else 422;
   - creates or updates today's check-in (user's timezone) through
     `CheckInService` (`find`, then `update` or `create`), like
     `CheckInController::store`;
   - setting the stored value again is a no-op success; the action is
     naturally idempotent;
   - success response with no payload; the widget re-fetches.
   A tracker that is not enabled can still be set (harmless); the widget only
   shows enabled ones.
3. **OpenAPI** annotations for both routes, documenting the `scale` action
   and the body.
4. **Tests:**
   - rows follow the enabled trackers in the app's order; defaults when the
     user has no settings; today's values; `null` when there is no check-in;
   - mood/sports/other options and colors; sports includes a stored sport
     that is no longer selected;
   - "today" follows the user's timezone; `refresh_at` is the next local
     midnight;
   - set: creates today's check-in; updates it; clears with `null`; 422 for
     out-of-range values (6 for mood, 7 for sports, a string); 404 for an
     unknown tracker; a widget token works; another user's check-in is never
     touched;
   - the widget token still gets 401 on `/check-in`.

## Out of scope

- Check-ins for other days than today.
- Changing which trackers are enabled from the widget.
- Any change to the app's own check-in endpoints.

## Notes

- New status in the action contract: **422** for an invalid value. The widget
  treats it like a failed action (revert + error).
