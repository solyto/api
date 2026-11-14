# Tasks: CalDAV outbound requests — redirects, IPv6 ranges, weak ETags

id: elderly
analyst: analyst
date: 2026-11-14

## Notes from analysis

- **Branch:** the worktree HEAD is `feature/elderly_caldav-outbound-requests-redirects-ipv6-ranges-weak-etags`, which matches the brief.
- **IPv6 "SIIT" range:** the brief lists `::ffff:0:0/96` as SIIT. That
  prefix is the IPv4-**mapped** range (`::ffff:a.b.c.d`), and
  `OutboundUrlGuard::isPublic()` (line 80) already handles it: it unwraps
  the address and checks the IPv4 address inside. That stays as it is
  (`ipv4-mapped loopback` is already tested). The SIIT IPv4-**translated**
  range (RFC 6052/7915) is `::ffff:0:0:0/96` (`::ffff:0:a.b.c.d`), and
  nothing covers it today. That is the prefix TASK-2 adds. If the owner
  really meant to block every mapped address, including public ones, that
  is a behaviour change and needs a separate decision.
- **Weak ETags are a real problem (brief step 3).**
  - RFC 9110 §13.1.1 says `If-Match` uses strong comparison, so
    `If-Match: W/"x"` never matches.
  - Nextcloud/ownCloud (sabre/dav), iCloud and Radicale send strong ETags.
  - nginx (since 1.7.3) turns a strong ETag into a weak one when it
    gzips a response. A self-hosted server behind nginx with gzip can
    therefore send `W/"x"` on GET.
  - Sabre compares `If-Match` as an exact string, so the remote answers
    412 to every update and delete.
  - Fix: strip `W/` in the outgoing `If-Match` header only. Do not leave
    `If-Match` out, because that drops conflict detection. Do not strip it
    in `normalizeEtag()` either: the stored ETag is what our own DAV
    clients see and send back to us, and sabre on our side matches it
    exactly.
- **"Report as import error" (brief step 4):** `CalendarImportDTO` /
  `ImportStateResource` have no error field, and adding one would change
  the API. TASK-4 therefore follows redirects and logs a failed
  collection with host and status (same pattern as the existing
  "Calendar import skipped a calendar" log), without failing the whole
  import. A user-visible error field would be follow-up scope and is not
  in this job.
- **Real listener (brief step 5):** the guard blocks loopback. The test
  therefore binds an `OutboundUrlGuard` subclass whose `check()` returns
  `dav.test:<port>:127.0.0.1`. The request to `http://dav.test:<port>/`
  can only succeed if `CURLOPT_RESOLVE` is applied, which proves the
  pinning. `docs/TESTING.md` says "never a live call". A loopback
  `php -S` process is not an external call, but the doc should say so
  (TASK-6).

## Task breakdown

TASK-1: In `RemoteCalDavClient::sendFollowing()`, refuse a redirect whose resolved `Location` goes from `https` to `http`, throwing `unavailable($url, $status, 'insecure redirect')` before the next hop is sent; add a test that the plaintext hop is never requested (`Http::assertNotSent`) and that discovery returns `[]`.
  files: app/Dav/Services/RemoteCalDavClient.php, app/Dav/Tests/RemoteCalDavClientTest.php
  depends: none
  risk: low — small check inside an existing loop. Discovery already catches the exception and falls back, and cross-host https redirects (iCloud) still pass.

TASK-2: Add `64:ff9b:1::/48`, `::ffff:0:0:0/96` (SIIT, see notes), `2002::/16` and `fec0::/10` to `OutboundUrlGuard::BLOCKED_RANGES`, and add one blocked-URL dataset case per range (e.g. `http://[64:ff9b:1::a00:1]/`, `http://[::ffff:0:a00:1]/`, `http://[2002:a00:1::1]/`, `http://[fec0::1]/`) to `tests/Unit/OutboundUrlGuardTest.php`.
  files: app/Shared/Services/OutboundUrlGuard.php, tests/Unit/OutboundUrlGuardTest.php
  depends: none
  risk: low — the lists only grow. Check that `inRange()` handles the non-byte-aligned `/10` (it uses a remainder mask), and that the public-address test still passes.

TASK-3: In `RemoteCalDavClient::ifMatchHeader()`, drop a leading `W/` from each entity tag, including every item of a comma-separated list, so the remote's strong comparison can match. Leave `normalizeEtag()` and the stored ETags unchanged. Add tests: a remote that issues `W/"…"` on GET accepts a PUT and a DELETE with that tag, and a stale weak tag still gets 412.
  files: app/Dav/Services/RemoteCalDavClient.php, app/Dav/Tests/RemoteCalDavClientTest.php (or app/Dav/Tests/ExternalCalendarProxyTest.php), tests/Support/FakeCalDavServer.php (an opt-in switch to send weak ETags on GET)
  depends: none
  risk: medium — touches the conflict path of every external-calendar write. A wrong strip (e.g. on the list form) would turn safe 412s into silent overwrites, so the stale-tag test is required.

TASK-4: Make `CalendarImport::getEvents()` follow redirects: give `RemoteCalDavClient::send()` the `sendFollowing()` path (so every hop is re-checked by the guard and the TASK-1 downgrade check applies) and return the response. In `getEvents()`, log a non-2xx or unavailable collection on the `dav` channel (host and status only, never credentials) instead of silently returning `[]`. Add `DavImportTest` cases: a collection that answers 301 to another public URL imports its events, and one that answers 500 logs and imports 0 without breaking the rest of the import.
  files: app/Dav/Services/RemoteCalDavClient.php, app/Dav/Services/CalendarImport.php, app/Dav/Tests/DavImportTest.php
  depends: TASK-1
  risk: medium — changes the import's network behaviour. `send()` has no other callers (only `CalendarImport`), but REPORT bodies are re-sent on each hop, and the log must not leak the URL's user info or the credentials.

TASK-5: Add a test that runs `RemoteCalDavClient` against a real local HTTP listener. A `php -S 127.0.0.1:<free port>` process with a small router script is started from the test and stopped in `afterEach`, with no `Http::fake`. A test-only `OutboundUrlGuard` subclass pins `dav.test` to `127.0.0.1`. The test covers:
  (a) a request to `http://dav.test:<port>/…` succeeds, which proves `CURLOPT_RESOLVE` pinning;
  (b) a response with `Content-Length` over `calendars.external.max_response_bytes` throws `ExternalCalendarUnavailableException` (the `on_headers` callback);
  (c) a chunked response without `Content-Length` over the cap throws it too (the `progress` callback).
  files: app/Dav/Tests/RemoteCalDavClientLiveTest.php (new), tests/Fixtures/dav/http-router.php (new router script)
  depends: none
  risk: high — new kind of test infrastructure. Process spawning, free-port selection and waiting for readiness can be flaky in CI or the container. Guzzle may wrap exceptions thrown from `on_headers`/`progress` differently than expected; check that they end in `ExternalCalendarUnavailableException` and fix the client if they don't. Fallback (brief step 5): if a listener proves unreliable here, assert the built curl options (`CURLOPT_RESOLVE` entry, both callbacks) through Guzzle's handler stack. Say plainly which path was taken.

TASK-6: Update `docs/TESTING.md`: add the new live-listener test to the DAV files list, coverage map and caveats, and state that a loopback listener is allowed while external calls stay faked. Then archive or close `docs/backlog/caldav-outbound-hardening.md` the way the backlog workflow expects.
  files: docs/TESTING.md, docs/backlog/caldav-outbound-hardening.md
  depends: TASK-5 (and TASK-1..4 for closing the backlog entry)
  risk: low — documentation only.

All tasks: `composer test` and `composer analyse` must both exit 0 in the worktree before the job is done.
