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
