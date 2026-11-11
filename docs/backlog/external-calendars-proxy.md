# Backlog: external CalDAV calendars as fully functional calendars, via proxy

created: 2026-11-11

## What

Users connect a calendar on an external CalDAV server (Nextcloud, iCloud,
Fastmail, Radicale, Baïkal, …) to solyto. In solyto it behaves exactly like a
native calendar: listed, readable and **editable** through the API and over
solyto's own DAV server, and included in widgets, the daily reminder, `/day`
and every other place that reads calendars.

solyto stores **no copy of the events**. Every object-level operation on an
external calendar is proxied to the remote server at the sabre backend level.
The remote server is the only source of truth.

Background: `docs/reports/external-calendars-via-dav-2026-11-08.md` (§1–§3
for the legacy Nextcloud integration, the one-time import and the current DAV
setup). Its recommendation (read-only mirror, ICS first) is **superseded** by
the decisions below.

## Why

Users with an existing CalDAV server want their calendars in solyto without
moving them. A read-only mirror was rejected: if it can't be edited, it isn't
worth building. A two-way mirror was rejected: keeping two copies 100 % in
sync (conflicts, echo suppression, push queues) is the expensive, risky part.
Proxying avoids both.

## Decisions (fixed, from the 2026-11-11 discussion with the owner)

Nothing is left open.

1. **Fully functional, two-way.** Read-only is out. ICS/webcal subscriptions
   are out, since an ICS feed can't be written to.
2. **No Google.** No OAuth. Auth is HTTP Basic with username + app password
   only.
3. **Behaves exactly like a native calendar,** including being served over
   solyto's DAV to the user's own clients. Users who also sync the same remote
   calendar directly on a device will see it twice; that's accepted and not
   worked around.
4. **Proxy, not mirror.** No event data is stored in pgsql for external
   calendars.
5. **Proxy at the sabre backend level, not at the HTTP level.** Forwarding raw
   DAV requests would need merged home listings, href rewriting in XML, and a
   second client for the API anyway. The backend is the one place both the
   API and DAV clients reach.
6. **Remote wins.** Writes pass the client's `If-Match` through. A `412` from
   the remote is returned to the caller as-is. No local copy, no merge.
7. **No sharing inside solyto.** External calendars can't be shared with other
   solyto users (API share endpoints and DAV sharing both refuse).
8. **No limits for now** (accounts, calendars, sizes, intervals).
9. **No monitoring for now** (no failure counters, auto-disable or alerts).
10. **Short cache for external calendars: 5 min.** Every event cache entry in
    `CalendarService` (`calendar_events` keys, today 15 min / 1 h / 7 d) for
    an external calendar gets a 5 min TTL, since remote changes made
    elsewhere never invalidate our cache. Configurable.
11. **5 s timeout on every remote call** (connect + response). Configurable.
    Slower daily reminders (`SendDailyDayRemindersCommand` loops users
    sequentially) are acceptable.
12. **Outages:** an unreachable external calendar simply doesn't show up. The
    API **reports** it; the app decides what to show (e.g. a notification).
    DAV clients get a `503` for that collection only. One failing calendar
    never fails a whole listing.
13. **Hrefs: translate by file name, no mapping table.**
    - Remote → solyto: last segment of the remote href, URL-decoded, is our
      object URI.
    - Solyto → remote: URL-encode the name and append it to the stored remote
      collection URL.
    - Remote hrefs that are not direct children of the remote collection
      (subfolders, other host) are skipped and logged, not supported.
    - New events are PUT under the name solyto generates (as today:
      random hex + `.ics`); we assume the remote keeps it.
    - A mapping table is only added later if a real provider turns out to
      rename objects on create.
14. **Calendar properties are local only.** Name, colour and order changed in
    solyto are stored on the native pgsql row and never sent to the remote
    (no `PROPPATCH`).
15. **Never modify the remote server beyond events.** Removing an external
    calendar in solyto removes the link and the native pgsql row only. The
    remote calendar is never deleted, and there's no "also delete on server"
    option.
16. **Credentials per account.** One account (server, username, encrypted app
    password) has many connected calendars. Changing the app password is one
    update on the account.
17. **No creating calendars on the remote server** from solyto (no
    `MKCALENDAR`). Users create them on their server and connect them.

## Design

**Calendar = native row, contents = proxied.**

- Each external calendar gets a normal `calendars` + `calendarinstances` row
  in pgsql (name, colour, order, ids), owned by the user, with **no
  objects**. Calendar listing, ordering, `{instanceId}` API routes and the DAV
  home `PROPFIND` keep working unchanged.
- Two new MariaDB tables (decision 16), migrations shown before they run:
  - **accounts:** user (FK on `users`, cascade), server URL, username, app
    password (`encrypted` cast; precedent
    `app/Plugins/Models/UserPlugin.php:33`).
  - **calendars:** account (FK, cascade), remote collection URL, pgsql
    `calendarid` / `instanceId` of the native row.
- `AppCalendarsPDO` (`app/Dav/Backend/AppCalendarsPDO.php`) checks per call
  whether the calendar is external (one cached per-user lookup, so native
  users pay nothing noticeable) and routes object operations to a new remote
  client class:

  | backend method | remote request |
  |---|---|
  | `getCalendarObject` | `GET` |
  | `createCalendarObject` / `updateCalendarObject` | `PUT` (+ `If-Match` / `If-None-Match: *`) |
  | `deleteCalendarObject` | `DELETE` |
  | `getCalendarObjects` | `PROPFIND` Depth 1 (`getetag`, `getcontenttype`, `getcontentlength`) |
  | `getMultipleCalendarObjects` | `calendar-multiget` REPORT |
  | `calendarQuery`, `getEventObjectsInRange` | `calendar-query` REPORT with `time-range`, returning `calendar-data` |
  | `getChangesForCalendar` | `sync-collection` REPORT |

  The list is a starting point. Step 2 confirms every backend method sabre's
  CalDAV, Sync and our own code paths call.
- **Event expansion stays as is.** `getExpandedEventsCustom` keeps its
  occurrence loop; for external calendars its rows come from the
  `calendar-query` result instead of the pgsql query.
- **Sync tokens pass through.** sabre only requires the
  `http://sabre.io/ns/sync/` prefix and hands the rest to the backend
  (`vendor/sabre/dav/lib/DAV/Sync/Plugin.php:113-121`, token from
  `CalDAV/Calendar.php:366-380`). Put the remote token behind the prefix
  (encoded so it survives as a URI suffix); no token table. If the remote
  doesn't support `sync-collection`, return no sync token for that calendar
  so clients fall back to ctag/ETag.
- **ctag / ETags pass through** from the remote collection and objects.
- **Calendar-level operations stay local** (decisions 14, 15, 17): property
  changes only touch the native row; removing a calendar deletes the link row
  and the native pgsql row; removing an account removes all its calendars
  that way. Nothing calendar-level is ever sent to the remote.
- **Cache invalidation for our own writes** works as today (the plugin and
  service `forgetByPrefix` calls); only the TTL differs (decision 10).
- **Adding a calendar:** reuse the import flow's discovery and selection
  (`CalendarImport`, `ImportController`), but with generic discovery:
  `/.well-known/caldav` (RFC 6764) → `current-user-principal` (RFC 5397) →
  `calendar-home-set` → collections, falling back to today's Nextcloud path
  `principals/users/<username>/` (`app/Dav/Services/CalendarImport.php:181`).
  The selected collections become external calendars instead of being
  imported. The app password is never cached in plaintext (today it sits in
  the cache for 60 min, `CalendarImport.php:233-236`).

**API reporting (decision 12).** `ApiResponse::success` already takes a
`$meta` array (`app/Api/ApiResponse.php:12-33`).

- `CalendarResource` gains `is_external` and an availability field (e.g.
  `unavailable: bool` or the last error time).
- Event listings (`calendars/events/{yearMonth}`, `events/widget`,
  `widgets/your-day`, today) leave a failing external calendar out of `data`
  and list its `instanceId` in `meta` (e.g. `meta.unavailable_calendars`), so
  the app can tell an outage from an empty calendar.
- OpenAPI annotations updated for both.

**Security.**

- Prerequisite: the SSRF URL guard from
  `docs/reports/bot-traffic-detection-2026-10-14.md` (§7 item 6): no private,
  loopback or link-local targets, re-checked after DNS and on redirects.
- Applies to the user-supplied URL **and** to every href the remote returns
  (host pinned to the account's host).
- Response size cap on remote reads.
- Credentials never in logs, cache or exceptions.

## Steps

1. SSRF guard (own job, prerequisite; also covers feeds, libraries and the
   import).
2. Read sabre's CalDAV/Sync plugins and our callers; list every backend
   method that touches objects and confirm the proxy table above.
3. Check what the frontend sends as `{eventId}` for event attachments
   (`EventAttachmentController`, `EventAttachmentService` key by a string
   `eventId`) and make sure external events get a stable value there.
4. Migrations (shown first) + models for the account and calendar tables.
5. Remote CalDAV client: Basic auth, short timeout, size cap, SSRF guard,
   href → name translation (decision 13).
6. Routing in `AppCalendarsPDO` + short TTL in `CalendarService`.
7. Generic discovery + "connect calendars" endpoints (reusing the import
   flow), sharing refused for external calendars.
8. API reporting (`CalendarResource`, `meta`, OpenAPI).
9. Tests with a fake remote CalDAV server (`Http::fake`): read, create,
   update, delete, `412` passthrough, sync-token passthrough, remote down
   (calendar omitted + reported, DAV `503`), non-child hrefs skipped,
   URL-encoded names round-trip, native calendars untouched.
10. Manual check per provider (Nextcloud, iCloud, Fastmail, Radicale,
    Baïkal): names kept on create, hrefs are direct children, sync-collection
    support.

## Out of scope

- Google / OAuth.
- ICS/webcal subscriptions.
- Mirroring or offline access to external events.
- Sharing external calendars with other solyto users.
- Limits, monitoring, auto-disable.
- The one-time import's other bugs (dropped UID/TZ/EXDATE, report §3), except
  the shared discovery and the plaintext secret.
- External address books (CardDAV).
- Pushing calendar properties to the remote, deleting remote calendars,
  creating remote calendars (decisions 14, 15, 17).

## Notes

- ⚑ Changes how the DAV backend serves calendars on the `pgsql` connection
  (a new kind of pgsql calendar with no objects). No pgsql schema change, no
  DAV route change. Flag in the job per the hard rules.
- Any new user-facing strings: `en` first, then the `translator` agent.
- Check: an external calendar on a fake remote can be listed, read, created
  in, edited and deleted through both `/api/v1/calendars…` and `dav/`, with
  no rows in `calendarobjects` for its `calendarid`; `composer test` and
  `composer analyse` exit 0.
