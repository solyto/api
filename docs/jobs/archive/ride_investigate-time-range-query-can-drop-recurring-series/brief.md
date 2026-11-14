# Brief: investigate — time-range query can drop recurring series

type: feature
id: ride
branch: feature/ride_investigate-time-range-query-can-drop-recurring-series
date: 2026-11-14
author: Leander Muskalla

## What

The time-range query (c6aca33) filters on Sabre's `firstoccurence` and
`lastoccurence` columns (`app/Dav/Backend/AppCalendarsPDO.php:524-525`).
Sabre computes them from the *first* non-VTIMEZONE component in the object
(`vendor/sabre/dav/lib/CalDAV/Backend/PDO.php`, `getDenormalizedData`).

1. **Override stored before the master (plausible).** An external DAV
   client may write a recurring object with an override (a VEVENT with
   RECURRENCE-ID) placed before the master VEVENT. The bounds then come from
   the override alone. The whole series is then only listed in windows about
   ±1 day around that override; before c6aca33 it was always listed. The
   app's own writes put overrides after the master, so they're not affected.
2. **2038 cap.** Sabre caps `lastoccurence` at `2038-01-01`. Infinite
   series, and series running past 2038, are no longer returned for windows
   after about 2037-12-31.

## Why

1 would make recurring events from some clients disappear from the app
without any error. It needs a reproduction before any change.

## Steps

1. Reproduce: PUT an object over DAV with the override first and the master
   second, then list a month without the override. Check what Sabre writes
   to `firstoccurence`/`lastoccurence`. Also check whether real clients
   (Thunderbird, Apple Calendar, DAVx⁵) write this order.
2. If it reproduces, pick one fix:
   - In the query, ignore the bounds for objects that have an RRULE: add a
     `componenttype`/RRULE marker column, or recompute the bounds from the
     master on write in `AppCalendarsPDO`.
   - Or reorder the components on write so the master comes first.
3. 2038: probably accept and document. If not, treat `lastoccurence >=
   2038-01-01` as unbounded in the query.
