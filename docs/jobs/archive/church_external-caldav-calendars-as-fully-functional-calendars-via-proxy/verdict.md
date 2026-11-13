# Verdict: external CalDAV calendars as fully functional calendars, via proxy

id: church
reviewer: @reviewer
date: 2026-11-13

## Review (round 2)

Base: `main` (`.manigot/manigot.json`, no `base:` in brief). Branch checked:
`feature/church_external-caldav-calendars-as-fully-functional-calendars-via-proxy`.
I reviewed the whole diff `main...HEAD` (55 files) against tasks.md and
implementation.md. Every changed file is accounted for in implementation.md.
The only removals are the ICalendarHelper discovery helpers and their unit
tests, which TASK-14 made dead code. That is in scope.

- `composer test`: exit 0, 1448 tests, 5176 assertions. Every test shows the
  environmental `file_get_contents(/workspace/.env)` warning because the
  worktree has no `.env`. The job didn't cause it.
- `composer analyse`: exit 0, no errors.
- `git status`: only `.env.testing` (a mounted character device, not
  committable) and `tokens.json` show as modified. Neither is job work.

The round-1 blockers (TASK-6 to TASK-18 missing, SSRF guard built in this
job, TASK-11 with option B) are resolved.

TASK-1: PASS
notes: Unchanged since round 1.

TASK-2: PASS
notes: Unchanged since round 1. Option B was implemented per the round-1 verdict.

TASK-3: PASS
notes: The build follows the design. The documented deviations are
reasonable: last-known ctag/token kept without expiry plus a fetch time,
calendar-query results prefetched for the following multiget, and the
per-principal MariaDB lookup instead of Redis.

TASK-4: PASS
notes: Unchanged. The migrations were run only by the SQLite suite.

TASK-5: PASS

TASK-6: PASS
notes: `OutboundUrlGuard` does the following:
- allows http/https only, with no userinfo in the URL;
- refuses private, reserved, CGNAT and IPv4-mapped/compatible addresses;
- pins the request through `CURLOPT_RESOLVE`.

`RemoteCalDavClient` does the following:
- turns automatic redirects off and checks each hop by hand in discovery;
- caps response size on headers, on progress and on the final body;
- uses the same timeout for connect and response;
- logs only the host, never credentials.

`childName` drops non-child and other-host hrefs. Hrefs are pinned to the
collection's host, not the account's server URL host (needed for iCloud).
The SSRF guard still runs on every request, so this is safe. It is
documented in implementation.md.

TASK-7: PASS
notes: GET, PUT (`If-None-Match: *` / `If-Match`), DELETE, PROPFIND Depth
0 and 1, multiget, calendar-query with time-range, and sync-collection
(400/403/409 → invalid token, 507 → truncated) are all there.

TASK-8: PASS
notes: `AppCalendarsPDO` routes every object-level method from the TASK-1
inventory, the sabre ones and the `*Custom` ones. Native calendars keep the
parent paths. `calendarQuery` applies sabre's exact filter locally on the
remote data. No `addChange()` or `calendarobjects` writes happen for
external calendars, and tests assert this.

TASK-9: PASS
notes: The token is `ext-` + base64url behind sabre's prefix. A foreign or
undecodable token → `null` → `InvalidSyncToken`. When the remote has no
sync-collection, the token is omitted. The remote ctag is cached for the
TTL and falls back to the last known value during an outage, so the
calendar never drops from the home listing.

TASK-10: PASS
notes: An unavailable remote raises a sabre `ServiceUnavailable` (503) for
that request only. `updateInvites` throws `Forbidden` for external calendars.
`ExternalPreconditionsPlugin` is the only DAV wiring change, and it is
flagged ⚑ in implementation.md. Existing `app/Dav/Tests/*` pass unchanged.

TASK-11: PASS
notes: The id is `2^52 | 52-bit sha256(link id/uri)`, so it stays exact in
JSON and never meets a native id. Attach and cleanup are covered by a test.

TASK-12: PASS
notes: `collectEvents()` handles all three listings. External calendars use
the configured TTL. Failures are isolated per calendar and never cached.
`CalendarService` is `scoped`, so `unavailableCalendars()` reads the same
instance. Reminder/gateway return shapes are unchanged.

TASK-13: PASS
notes: The resource gains `is_external` and `is_unavailable`.
`meta.unavailable_calendars` is on `events/{yearMonth}`, `events/widget` and
`widgets/your-day`. The OpenAPI annotations and the `EventListingMeta`
schema are added. The 412 → 412 and unavailable → 503 mappings are in
`bootstrap/app.php`. The brief also lists "today", but there is no API
endpoint for it: `listTodayEvents` only feeds the bot and the reminder,
which skip failed calendars.

TASK-14: PASS
notes: Generic discovery with the Nextcloud fallback is shared with the
import. The import secret is stored encrypted (`Crypt`), and its fetches go
through the guard.

TASK-15: PASS
notes: The endpoints check ownership (`isResourceOwner`). The password is
`encrypted` and `$hidden` and never returned. Only URLs that discovery
listed can be connected. Names are de-duplicated. Removing an account or a
calendar stays local.

TASK-16: PASS
notes: `assertShareable` → `AuthorizationException` → 403 on share, list
sharees and revoke.

TASK-17: PASS
notes: User deletion is covered by a test. The export proxies external
calendars and skips unreachable ones.

TASK-18: PASS
notes: The stateful `FakeCalDavServer` covers every item in brief step 9
and its "Check" note: CRUD via API and DAV, the 412 cases, sync
passthrough and fallback, 503 and meta, non-child and other-host hrefs,
encoded names, SSRF, no `calendarobjects` rows, native calendars untouched,
sharing refused, and credentials absent.

TASK-19: N/A
notes: This is a manual per-provider check for a human (brief step 10). It
doesn't block the agent work.

## Overall

APPROVED

No blockers. Before deploy, a human should:
- run the two MariaDB migrations (`2026_11_11_000001/2`), shown in the job;
- do the per-provider check (TASK-19).
