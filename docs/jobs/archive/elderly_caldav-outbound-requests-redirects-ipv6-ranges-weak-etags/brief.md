# Brief: CalDAV outbound requests — redirects, IPv6 ranges, weak ETags

type: feature
id: elderly
branch: feature/elderly_caldav-outbound-requests-redirects-ipv6-ranges-weak-etags
date: 2026-11-14
author: Leander Muskalla

## What

Edge cases in `app/Dav/Services/RemoteCalDavClient.php` and
`app/Shared/Services/OutboundUrlGuard.php`, found in the review of the
changes since 1.1.2:

1. **Credentials sent over a downgrade.** `sendFollowing()`
   (`RemoteCalDavClient.php:379-392`) follows redirects with the same Basic
   auth credentials and does no scheme check. A
   `/.well-known/caldav` → `http://…` redirect sends the app password in
   plaintext. Cross-host redirects are needed (iCloud); https→http is not.
2. **IPv6 ranges the guard doesn't block.** `isPublic()` reports these as
   public:
   - `64:ff9b:1::/48` (local-use NAT64)
   - `::ffff:0:0/96` (SIIT)
   - `2002::/16` (6to4, which can embed a private IPv4)
   - `fec0::/10` (deprecated site-local)
3. **Weak ETags (plausible).** `normalizeEtag()`
   (`RemoteCalDavClient.php:574`) keeps `W/"…"` and sends it as `If-Match`.
   Under strong comparison that never matches, so a server that issues weak
   ETags would answer 412 to every update and delete.
4. **Calendar import ignores redirects.** `CalendarImport::getEvents` now
   goes through `RemoteCalDavClient::send`, which doesn't follow redirects;
   the old `Http` client did. A collection URL that answers with a 301 now
   imports 0 events with no error.
5. **Test gap.** `FakeCalDavServer` replaces the transport and
   `tests/TestCase.php` gives the guard a fixed resolver. The
   `CURLOPT_RESOLVE` pinning and the response size limits
   (`RemoteCalDavClient.php:345-357`) are therefore never exercised.

## Why

1 and 2 are security hardening. 3 and 4 make some servers fail outright,
or fail silently. 5 means the DNS-rebinding and size protections are only
verified by reading the code.

## Steps

1. In `sendFollowing()`, refuse a redirect from `https` to `http` when
   credentials are attached. Throw the same "unavailable" error with a clear
   log message.
2. Add the four ranges to the guard's blocked list, and add cases to
   `tests/Unit/OutboundUrlGuardTest.php`.
3. Before changing weak-ETag handling, check against a real server (or the
   RFC behaviour of Nextcloud/iCloud/Radicale) that it's a real problem.
   If it is, strip `W/` before sending `If-Match`, or leave `If-Match` out
   for weak tags.
4. Make the import path follow redirects. Reuse `sendFollowing()`, which
   re-checks the guard on every hop. At minimum, report a non-2xx status as
   an import error.
5. Add one test that runs against a real local HTTP listener (not
   `Http::fake`) to cover pinning and the size cap. Alternatively, unit-test
   the curl options that are built.
