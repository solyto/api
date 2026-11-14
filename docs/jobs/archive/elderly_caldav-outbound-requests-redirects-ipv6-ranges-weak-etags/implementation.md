# Implementation: CalDAV outbound requests — redirects, IPv6 ranges, weak ETags

id: elderly
developer: developer
date: 2026-11-14

## Summary

Hardened the outbound CalDAV client and URL guard. Changes:
- Redirects from https to http are refused.
- Four more IPv6 ranges are blocked.
- Weak remote ETags are sent as strong tags in `If-Match`.
- The calendar import follows redirects and logs collections it cannot read.
- A new test runs the client against a real loopback listener, so curl pinning and the size cap are now exercised.

`composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `RemoteCalDavClient::sendFollowing()` throws `unavailable(..., 'insecure redirect')` when a hop goes from `https` to anything else. The check runs before the next request is sent. Test in `RemoteCalDavClientTest`: discovery returns `[]` and no `http://` request is sent.

TASK-2: Added `64:ff9b:1::/48`, `::ffff:0:0:0/96` (SIIT IPv4-translated, see the analyst note; `::ffff:0:0/96` mapped addresses were already unwrapped), `2002::/16` and `fec0::/10` to `OutboundUrlGuard::BLOCKED_RANGES`. Added one dataset case per range in `tests/Unit/OutboundUrlGuardTest.php`. Checked by hand that `/10` covers up to `feff::` and that public IPv6 addresses still pass.

TASK-3: `ifMatchHeader()` drops a leading `W/` from the single tag and from each item of a list. `normalizeEtag()` and the stored ETags are unchanged. `FakeCalDavServer` got an opt-in `$weakEtags` switch: GET then returns `W/"…"` while `If-Match` stays a strong comparison. Tests:
- `ExternalCalendarProxyTest`: PUT and DELETE through our DAV server against a weak-ETag remote.
- `RemoteCalDavClientTest`: a stale weak tag still gets 412 for PUT and DELETE, and a list is sent as strong tags.

TASK-4: `RemoteCalDavClient::send()` now goes through `sendFollowing()`, so every hop is guard-checked and the TASK-1 downgrade check applies. `CalendarImport::getEvents()` logs `Calendar import could not read a calendar` on the `dav` channel when a collection is unavailable or answers non-2xx. The log carries host and status only. `DavImportTest` covers two cases:
- A 301 to another host imports the events.
- A 500 on one collection logs and imports 0 events, and the other calendar still imports.

TASK-5: New `app/Dav/Tests/RemoteCalDavClientLiveTest.php`. It took the real-listener path, not the curl-options fallback. Setup:
- Starts `php -S` on a free `127.0.0.1` port per test (Symfony Process, stopped in `afterEach`).
- Binds a guard subclass that pins the unresolvable `dav.test` to `127.0.0.1`.

It covers:
- (a) pinning: the request succeeds, and the listener sees `Host: dav.test:<port>`.
- (b) a `Content-Length` over the cap.
- (c) a chunked response over the cap.

The test exposed a gap. Guzzle wraps an `on_headers` exception in a `TransferException`, which was logged as `connection`. An exception from `progress` is not wrapped, so it passed through without the failure log at all. `request()` now sets a flag in both callbacks, catches both paths and logs `reason: too large` with `status: null`. The tests assert that log entry, which proves the transfer was aborted mid-flight rather than caught by the final `strlen` check.

The router lives in `tests/Fixtures/http/caldav-router.php`, not `tests/Fixtures/dav/http-router.php` as planned, because `tests/Fixtures/dav/` holds client-shaped `.ics`/`.vcf` data.

TASK-6: `docs/TESTING.md` now has the new fixture dir, a rule that a loopback listener is allowed (external calls stay faked), the DAV files list, two coverage-map rows and a caveat on how the live test works. Removed `docs/backlog/caldav-outbound-hardening.md`, the same way earlier converted backlog entries were removed.

## Known issues / follow-ups

- `ContactImport` (`app/Dav/Services/ContactImport.php`) still uses the plain `Http` client: no `OutboundUrlGuard`, no `CURLOPT_RESOLVE` pinning, and Guzzle's default redirect following. It has the same SSRF and credential-downgrade exposure this job closed for calendars.
- Two `ExportTest` tests (`TodoExportService writes a csv`, `FinanceWealthExportService writes one row per field value`) write into `storage/app/public/user/` without creating it. On a fresh `storage/` tree they can fail depending on test order. Seen once on the first full run here; the next two runs passed.
- `docs/TESTING.md` says `Http::fake()` is used in "8 files". The current count is higher; I left it alone.
