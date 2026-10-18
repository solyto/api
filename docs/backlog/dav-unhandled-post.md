# Backlog: find out which clients POST to DAV and get 501

created: 2026-10-18

## What

`dav.log` has many `Sabre\DAV\Exception\NotImplemented: There was no plugin
in the system that was willing to handle this POST method` errors, in bursts
of 5–9 within a second (2026-10-14 20:44:59, 21:31:37; 2026-10-17 23:57:34; …).
Find out which paths and clients send them, then either handle the request
or decide it's scanner noise.

## Why

A 501 is logged at ERROR (Sabre logs ≥ 500 as error), so these sit next to
real failures. The bursts look like a sync client, not a scanner. Likely
candidates, given the plugins in `app/Dav/Factories/DavServerFactory.php:46-58`:

- Free-busy / scheduling POSTs to `calendars/<user>/outbox`: the
  `Sabre\CalDAV\Schedule\Plugin` is not registered.
- Sharing POSTs with a body type the `Sharing\Plugin` / `CalDAV\SharingPlugin`
  don't accept (e.g. Apple's `application/xml` share format).
- Browser plugin POSTs without a `sabreAction` field.

## Steps

1. Pull the matching lines from the reverse-proxy access log (path, user
   agent, content type) for the timestamps above.
2. If it's a real client feature (e.g. free-busy), write a separate job to
   support it. Adding the schedule plugin changes DAV behaviour, so flag it
   (rule 12.6).
3. If it's noise, it falls under [[dav-log-noise]].

## Notes

- Check: no `NotImplemented ... POST` lines in a day of `dav.log`, or a
  documented reason why they stay.
