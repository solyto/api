# Backlog: check-in custom counters (api)

created: 2026-11-03

## What

The api side of user-defined check-in counters, e.g. "Squats" in "reps". Each
user defines their own counters and stores any number per counter per day. The
UI is a separate app entry (`app/docs/backlog/check-in-custom-counters.md`).

- **Counters:** belong to a user and have a name, a unit, a position (for
  ordering) and a hidden flag. Endpoints to list, create, update (name, unit,
  hidden), reorder and delete them. Deleting a counter deletes its values.
- **Values:** one integer (zero or more) per counter per day, unique per
  counter and date. Endpoints to read a range of days and to set a day's value.
  Setting it to empty removes the entry. Setting it is idempotent, so the
  client sends the new total, not "+1".
- These are new tables. The built-in trackers on `check_in` (fixed columns,
  values 1–5) stay unchanged.

## Why

Users want to count things like reps or pages per day and graph them. The
fixed 1–5 trackers can't express that.

## Out of scope

- Daily goals, durations, yes/no habits.
- Widgets (`Widgets/`), Telegram and the daily check-in reminders.
- Any change to the built-in trackers or `check_in`.

## Notes

- Lives in the existing `app/Api/CheckIn/` domain, following its structure
  (Controllers, Services, Requests, Resources, Factories, Tests) and the
  domain's ownership checks (`isResourceOwner`).
- Use UUID keys, like `TimeTrackingProject`, and foreign keys to `users` with
  cascade delete, so the full user deletion routine covers it. Check-in data is
  cached under the `checkin` key (see `UserService`). Writes must invalidate it
  the same way check-in writes do.
- Reorder can be one endpoint that takes the ordered list of ids.
- Probably a per-user limit on the number of counters, to keep abuse in check.
  The developer picks a sensible number.
- Undecided:
  - Whether counters go into the export (`Export/Services/CheckInExportService.php`).
    Probably yes, but confirm.
  - Whether the +/− step size is stored per counter. If it is, it's just
    another field on the counter.
- The client contract test (`tests/Unit/ClientContractTest.php`) only guards
  existing routes. New routes are fine.
