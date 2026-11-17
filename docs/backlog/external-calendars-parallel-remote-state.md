# Backlog: refresh external calendar state in parallel on the DAV path

created: 2026-11-14

## What

`AppCalendarsPDO::remoteState()` runs inside `getCalendarsForUser()`, which
Sabre calls on almost every DAV request. When the cached state expires, one
request sends one PROPFIND per external calendar, one after the other, each
with a 5 s timeout. Several concurrent requests can all refresh at once.

- Send the PROPFINDs for all of a user's external calendars concurrently
  (Guzzle/Laravel `Http::pool`, as the OPML import does).
- Let only one request refresh a user's state at a time (a lock); the others
  serve the previous state or treat the calendars as unavailable.

## Why

Ten external calendars on a host that has gone silent make the refreshing
request take about 50 s, past normal PHP and proxy limits, so the client gets
a 504/500. The 5-minute "unavailable" window for failing remotes (added after
the 1.1.2 review) limits this to one slow request per window, which is why
this was backlogged rather than fixed then.

## Notes

- This is the hot path for every DAV request; flag the change (rule 12.6) and
  test it against `tests/Support/FakeCalDavServer.php` with a slow fake.
- Keep the shared "unavailable" state that the API listing path uses.
- Check: a DAV PROPFIND on the calendar home with 10 external calendars on a
  silent host returns within one timeout (~5 s), not ten.

## Update 2026-11-17: the app's API path does the same

Laravel Pulse (production, 1.1.3, past 7 days, 1000 ms threshold) shows slow
calendar requests from the **app**, not from DAV clients:

| Route | Count | Slowest |
|---|---|---|
| `GET /api/v1/calendars/events/{yearMonth}` (`listEvents`) | 5 | 13,628 ms |
| `GET /api/v1/calendars/events/widget` (`listWidgetEvents`) | 1 | 15,449 ms |
| `DELETE /api/v1/calendars/{instanceId}/events/{eventUri}/occurrence/{occurrenceDate}` | 1 | 10,040 ms |

`CalendarService::collectEvents()`
(`app/Api/Calendars/Services/CalendarService.php`, used by `listEvents`,
`listWidgetEvents` and `listTodayEvents`) loops over the user's calendars
and, for each external calendar without cached events, calls
`events()->listExpanded()` against the remote **one after another**. Each
remote call is capped by `calendars.external.timeout` (5 s,
`config/calendars.php`). Redirects (`RemoteCalDavClient::MAX_REDIRECTS = 5`)
can add further calls. Several slow or silent remotes add up: 13–15 s is about
three calls at the limit. The 5-minute unavailable flag
(`calendars.external.unavailable_ttl`) limits it to one slow request per
window, which matches the low counts.

Also:

- **No coordination between concurrent requests.** The app loads the month,
  the widget and "today" around the same time, each with its own cache key
  (`calendar_events_{user}_{calendar}_{suffix}`). On a cold cache each of
  them asks the remote separately, so a silent host stalls all three.
- **Deleting an occurrence** needs two remote calls (load, then save). 10,040
  ms is two 5 s timeouts, so the remote was unreachable. Check that the user
  got a clear error, and whether the unavailable flag is respected or set on
  that path.

What the fix should cover on the API path too:

- Fetch the external calendars of one listing concurrently (`Http::pool`, or
  at least one request per remote host in parallel), so a listing waits at
  most about one timeout.
- Optionally a short per-user lock or shared in-flight result, so the month,
  widget and today requests don't each hit a silent remote.

Not proven per request: Pulse does not show which calendars were involved, so
a slow native calendar (e.g. heavy recurrence expansion) can't be fully ruled
out for these entries.
