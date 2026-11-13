# Implementation: external CalDAV calendars as fully functional calendars, via proxy

id: church
developer: @developer
date: 2026-11-13

## Summary

Users can add an account on their own CalDAV server (server URL, username,
app password), pick calendars from it, and use them in solyto like native
calendars: through the API, over solyto's DAV server, in widgets, the
daily reminder, `/day` and the export. Each connected calendar is a normal
pgsql calendar row with no objects plus a MariaDB link row. Every
object-level operation in `AppCalendarsPDO` goes to the remote server
through `RemoteCalDavClient`, so solyto stores no copy of the events.
Calendar-level operations (name, colour, order, delete) stay local.

Remote writes pass `If-Match` through, and a remote `412` is returned as
`412`. Sync tokens and ctags come from the remote. If a calendar's server
is unreachable, DAV answers `503` for that collection only, and the API
leaves the calendar out of event listings and lists its id in
`meta.unavailable_calendars`. All outbound requests go through a new SSRF
guard (`OutboundUrlGuard`), built in this job as the verdict asked.

`composer test` (1448 tests) and `composer analyse` both exit 0.

⚑ **pgsql / DAV flag (hard rule).** No pgsql schema change and no DAV route
change. `AppCalendarsPDO`, the DAV backend on the `pgsql` connection, now
serves a new kind of calendar: a native row whose objects live on a remote
server. It also refuses DAV sharing for those calendars (`403`), and
`DavServerFactory` registers one new plugin (`ExternalPreconditionsPlugin`).
Native calendars keep the parent code paths, and the existing DAV tests
(`app/Dav/Tests/*`) are green unchanged.

## TASK-1: backend method inventory

DAV server plugins loaded (`app/Dav/Factories/DavServerFactory.php`): Auth,
ACL, Browser, CalDAV, PrivacyPlugin, CardDAV, Sync, Sharing, CalDAV
SharingPlugin, CacheInvalidationPlugin. Not loaded: Schedule and ICSExport,
so `getCalendarObjectByUID` and the scheduling-object methods are never
called. Subscriptions (`getSubscriptionsForUser`) are a separate local
listing and unaffected.

### sabre node → backend calls on objects

| caller | backend method | external routing |
|---|---|---|
| `Calendar::getChild` / `childExists` (`CalDAV/Calendar.php:105,163`) | `getCalendarObject` | remote `GET` |
| `CalendarObject::get` when no `calendardata` loaded (`CalendarObject.php:85`) | `getCalendarObject` | remote `GET` |
| `Calendar::getChildren` (`:122`) | `getCalendarObjects` | `PROPFIND` Depth 1 |
| `Calendar::getMultipleChildren` (`:144`, used by `calendar-multiget`) | `getMultipleCalendarObjects` | `calendar-multiget` REPORT |
| `Calendar::createFile` (`:199`) | `createCalendarObject` | `PUT` + `If-None-Match: *` |
| `CalendarObject::put` (`:103`) | `updateCalendarObject` | `PUT` + `If-Match` |
| `CalendarObject::delete` (`:115`) | `deleteCalendarObject` | `DELETE` (+ `If-Match`) |
| `Calendar::calendarQuery` (`:354`; `calendar-query` and `free-busy-query` REPORTs, `CalDAV/Plugin.php:598,668`) | `calendarQuery` | `calendar-query` REPORT, returns names |
| `Calendar::getChanges` (`:453`; Sync plugin) | `getChangesForCalendar` | `sync-collection` REPORT |
| `Calendar::getSyncToken` (`:366-380`) | none — reads `{http://sabredav.org/ns}sync-token` from calendar info | value set in `getCalendarsForUser` (TASK-9) |
| `CalendarHome::getChildren` / `getChild` (`CalendarHome.php:140,184`) | `getCalendarsForUser` | stays local; ctag / sync-token passthrough per TASK-9 |
| `Calendar::propPatch` (`:71`) | `updateCalendar` | local only (decision 14) |
| `Calendar::delete` (`:207`) | `deleteCalendar` | local only + link row removal (decision 15) |
| `SharedCalendar::updateInvites` / `getInvites` / `setPublishStatus` (`SharedCalendar.php:56,77,90`) | `updateInvites` etc. | refused for external (decision 7) |
| `CalendarHome::shareReply` (`:330`) | `shareReply` | n/a (no invites for external) |

Sabre's own `createCalendarObject` / `updateCalendarObject` /
`deleteCalendarObject` also call `addChange()` on the local
`calendarchanges` table; the external branch must skip that (and must not
touch `calendars.synctoken`) — the remote sync token is the only one.

### Our own reads/writes that bypass sabre's node layer

All in `app/Dav/Backend/AppCalendarsPDO.php`, reached through
`App\Dav\Services\Events` / `Calendars`:

| method | today | external routing |
|---|---|---|
| `getEventsCustom` (`:217`; export via `Events::list`, `CalendarExportService.php:33`) | all `calendarobjects` rows | `calendar-query` REPORT without time-range (full `calendar-data`) — or skip in export, decided in TASK-17 |
| `getEventObjectsInRange` (`:246`) | pgsql range query | `calendar-query` REPORT with `time-range` (padded by a day as today) |
| `getExpandedEventsCustom` (`:262`) | loop over `getEventObjectsInRange` | unchanged; rows come from the remote |
| `getEventByUriCustom` (`:386`; `Events::get/create/update` re-reads) | pgsql row | remote `GET` |
| `createEventCustom`, `importEventCustom` (`:406,414`) | `parent::createCalendarObject` | remote `PUT` + `If-None-Match: *` |
| `updateEventCustom` (`:421`) | `parent::updateCalendarObject` | remote `PUT` + `If-Match: <dto etag>` |
| `deleteEventCustom` (`:426`) | delete + re-read to confirm | remote `DELETE`; success from the status code, no re-read |
| `deleteOccurrenceCustom`, `updateOccurrenceCustom` (`:579,616`) | `readRecurringObject` + `parent::updateCalendarObject` | remote `GET` + `PUT` with `If-Match` of the read ETag |
| `readRecurringObject` (`:687`) | `$this->getCalendarObject` | covered once `getCalendarObject` is routed |
| `listCalendarsCustom`, `getCalendarByIdCustom`, `getCalendarByNameCustom` | local rows | local; plus `isExternal` flag on the DTO |
| `createCalendarCustom`, `updateCalendarCustom`, `updateCalendarOrderCustom`, `deleteCalendarCustom` | local | local (decisions 14, 15, 17) |
| `updateInvites`, `shareCalendarWithUserCustom`, `getCalendarSharees`, `unshareCalendar` | local | refused for external (decision 7, TASK-10/16) |

Other pgsql readers of event data outside the backend:
`StatisticsService.php:53` (counts `calendarobjects`; external calendars
simply contribute 0, which is correct — no copy is stored) and the Eloquent
`App\Dav\Models\Calendar::objects()` relation (no callers outside tests).

### Result

The brief's proxy table is confirmed and extended with: `getEventsCustom`
(export), `getEventByUriCustom`, `deleteEventCustom`'s re-read, the two
occurrence methods (via `getCalendarObject`), suppression of the local
`addChange()`/synctoken bump, and sharing refusal on `updateInvites`.
`calendarQuery` needs the remote REPORT to return only names; sabre then
loads each match through `getMultipleChildren` → multiget.

## TASK-2: event-attachment ids for external events (owner decision needed)

Facts:

- Routes `calendars/events/{eventId}/attachments/{todos,notes}`
  (`routes/api.php:203-208`) take a free string; `EventAttachmentService`
  stores it in `calendar_event_{todos,notes}.calendar_object_id`, an
  `unsignedBigInteger` with a unique key on (`calendar_object_id`,
  `todo_id`/`note_id`) and no user in the key.
- The only value the API exposes that fits is `EventResource.id` =
  `EventDTO::$id` (`?int`) = pgsql `calendarobjects.id`.
  `CalendarController::destroyEvent` (`:458`) cleans up with
  `(string) $event->id`. So the frontend almost certainly sends `id`; that
  can't be confirmed from this repo.
- External events have no `calendarobjects` row, so `id` would be `null`.
  The `etag` changes on every edit and the remote href is not numeric.

Options:

| | what | schema | API / frontend | stability |
|---|---|---|---|---|
| A | No attachments on external events: `id` stays `null`, attachment endpoints refuse ids that aren't native | none | frontend must hide the attachment UI when `id` is null | n/a |
| B | Synthetic integer id: 63-bit hash of (external link row id, object name), forced into the upper half of the range (bit 62 set) so it can't meet a native autoincrement id | none | none if the frontend uses `id` | stable while the remote keeps the file name (decision 13 already assumes this); moving the event to another calendar changes it — same as native today, where a move creates a new row |
| C | String key column `event_key` (`<calendarid>/<uri>` for every event) next to `calendar_object_id`, backfilled for native rows; `EventResource` gains `attachment_key` | MariaDB migration + backfill from pgsql | frontend must switch to the new field | as B |
| D | Change `calendar_object_id` to a string and send a string `id` for external events | migration altering the column | `id` changes type (breaks STANDARDS §13 additive rule) | as B |

Recommendation: **B** — no migration, no frontend change, behaviour identical
to native events. A is the fallback if attachments on external events aren't
wanted. TASK-11 waits for the owner's pick.

## TASK-3: protocol design

### If-Match / 412 (decision 6)

DAV path. `Server::checkPreconditions` (`DAV/Server.php:1305`) already runs
before PUT/DELETE: it resolves the node (→ `getCalendarObject` → remote
`GET`) and compares the client's `If-Match` / `If-None-Match` with the
remote ETag, so a stale client gets sabre's own `412` without a write. To
close the window between that `GET` and the write, the client's headers are
passed through as well:

- A small server plugin (`App\Dav\Plugins\ExternalPreconditionsPlugin`,
  `beforeMethod:PUT` and `beforeMethod:DELETE`) copies the request's
  `If-Match` and `If-None-Match` onto the backend
  (`AppCalendarsPDO::setRequestPreconditions(?string $ifMatch, ?string $ifNoneMatch)`).
  The backend ignores them for native calendars.
- External `createCalendarObject` always sends `If-None-Match: *` (never
  overwrite on create); `updateCalendarObject` / `deleteCalendarObject` send
  the client's `If-Match` when present, otherwise none (a client that sends
  none asked for an unconditional write).
- Return value: the remote response's `ETag` header if present, else `null`
  (sabre then omits the ETag, which is the documented behaviour for
  servers that may alter data).

API path. `updateEventCustom` sends `If-Match: <UpdateEventRequest etag>`
(already required by the request). The occurrence methods send the ETag of
the object they just read in `readRecurringObject`. `deleteEventCustom` sends
the ETag of the event the controller just read. Creates send
`If-None-Match: *`.

412 mapping. The client throws
`App\Dav\Exceptions\ExternalCalendarPreconditionFailedException`, which
extends `Sabre\DAV\Exception\PreconditionFailed`: sabre serialises it to
`412` for DAV clients unchanged, and `bootstrap/app.php` gets one mapping to
`ApiResponse::error(..., 412)` for the API (the remote body is never
forwarded).

### Sync tokens and ctag

- Encoding: `http://sabre.io/ns/sync/` + `ext-` + base64url(remote token).
  The `ext-` marker keeps it apart from native integer tokens; base64url
  survives as a URI suffix whatever the remote token looks like (Nextcloud's
  is itself a URL).
- `getChangesForCalendar`: `null` → `sync-collection` with an empty token;
  otherwise strip `ext-`, decode, send. A token that doesn't decode, or a
  remote `403`/`409` with `valid-sync-token`, returns `null` → sabre's
  `InvalidSyncToken`, which makes the client do a full resync. Remote
  responses: `404` entries → `deleted`, everything else → `modified`
  (sabre renders added and modified the same way); a `507` on the request
  URI → `result_truncated`. Hrefs translated per decision 13; non-child
  hrefs dropped and logged.
- Calendar info for external calendars (`getCalendarsForUser`,
  `getCalendarByIdCustom`, `getCalendarByNameCustom`) gets `getctag` and
  `{http://sabredav.org/ns}sync-token` from a remote `PROPFIND` Depth 0
  (`getctag`, `sync-token`, `supported-report-set`), never from the local
  row. If the remote reports no `sync-token` / no `sync-collection`, the
  sync-token key is left out, so `Calendar::getSyncToken()` returns `null`
  and clients fall back to ctag/ETag. If there's no `getctag`, the ctag is
  the encoded sync token; with neither, it's left out.
- Cost: `getCalendarsForUser` runs on every DAV request inside a home
  (`CalendarHome::getChild` also calls it), so the Depth 0 result is cached
  per external calendar for the external TTL (5 min, TASK-5) and memoised per
  request; our own writes forget it. The API path (`listCalendarsCustom`,
  `getCalendarByIdCustom` from `Calendars::get`) does not expose ctag/sync
  token, so it skips the remote call entirely.
- The local `calendars.synctoken` / `calendarchanges` are never touched for
  external calendars (no `addChange()`).

### Outage → 503 for one collection only (decision 12)

- The client throws `App\Dav\Exceptions\ExternalCalendarUnavailableException`
  (extends `Sabre\DAV\Exception\ServiceUnavailable`) on connect errors,
  timeouts, 5xx, an oversized body, an SSRF refusal or an unparsable
  response. Thrown from object-level backend methods, sabre turns it into a
  `503` for that request only.
- The home listing must not fail and must **not drop** the calendar (a
  client would delete its local copy). If the Depth 0 call fails, the
  calendar is still listed with the last cached ctag/sync token, or with
  neither if there is none; the failure is not cached. Any request into the
  collection then gets `503`.
- API: `CalendarService` catches the exception per calendar (TASK-12) and
  reports the `instanceId` in `meta.unavailable_calendars`. Single-event
  endpoints let it reach `bootstrap/app.php`, mapped to `503`.

## Changes

TASK-1 to TASK-3: analysis and design, see above (no code). The build
follows the TASK-3 design with these details:
  - "Last known" ctag/sync token: the remote Depth 0 result is stored without
    expiry together with its fetch time. It is refreshed after the external
    TTL and marked stale after our own writes. When the remote is down, the
    stored values are served.
  - A calendar-query hands the objects it already read to the multiget sabre
    runs right after it (one-shot, per request), so they aren't fetched
    twice.
  - The native-or-external lookup is one MariaDB query per principal and
    request (memoised in the backend instance), not a Redis entry. Every
    calendar in the listing is marked, so the object calls that follow need
    no further lookup, and there's no cache to keep in sync on connect or
    remove.
TASK-4, TASK-5: unchanged from the first pass (migrations
  `2026_11_11_000001/2`, models, factories, `config/calendars.php`). The
  migrations have only been run by the in-memory SQLite test suite, never
  against MariaDB. Run them with `php artisan migrate` on deploy.
TASK-6, TASK-7: `app/Shared/Services/OutboundUrlGuard.php` (+
  `BlockedUrlException`) does the following:
  - allows http/https only, with no credentials in the URL;
  - resolves the host and refuses private, loopback, link-local, CGNAT and
    reserved addresses (including IPv4-mapped IPv6);
  - pins the request to the checked address (`CURLOPT_RESOLVE`), so DNS
    rebinding can't swap in an internal address afterwards.

  `app/Dav/Services/RemoteCalDavClient.php` handles all remote requests:
  - Basic auth, with the configured timeout on both connect and response;
  - the response size cap (on headers, on progress and on the final body);
  - no automatic redirects. Discovery follows them by hand and checks every
    hop with the guard.
  - remote operations: GET, PUT with `If-None-Match: *` or `If-Match`,
    DELETE, PROPFIND Depth 0 and 1, calendar-multiget, calendar-query with
    time-range, sync-collection, and generic discovery;
  - href → name translation per decision 13. Hrefs that aren't direct
    children, or that point to another host, are skipped and logged with
    the host only.

  Errors become `ExternalCalendarUnavailableException` (a sabre `503`) or
  `ExternalCalendarPreconditionFailedException` (a sabre `412`). Neither
  carries credentials.
TASK-8 to TASK-10: `app/Dav/Backend/AppCalendarsPDO.php` routes these to
  the remote: `getCalendarObject`, `getCalendarObjects`,
  `getMultipleCalendarObjects`, `create/update/deleteCalendarObject`,
  `calendarQuery` (the exact filter runs locally on the returned data), and
  `getChangesForCalendar` (`ext-` + base64url token; a foreign or refused
  token → `null` → sabre's invalid-sync-token, which makes the client
  resync). The `*Custom` methods route too: events, range reads, event by
  URI, create/update/delete, and occurrences (written with the ETag they
  read). External objects never touch `calendarobjects` / `calendarchanges`.
  `getCalendarsForUser` replaces ctag/sync token with the remote values; it
  leaves the sync token out when the remote has no sync-collection.
  `deleteCalendar` also removes the link row. `updateInvites` throws
  `Forbidden` for external calendars. New
  `app/Dav/Plugins/ExternalPreconditionsPlugin.php` hands the client's
  `If-Match` to the backend. Wiring is in
  `app/Dav/Factories/DavServerFactory.php`. `CalendarDTO` gains `isExternal`.
TASK-11: option B from TASK-2. An external event's `id` is
  `2^52 | first 52 bits of sha256(link id + "/" + object name)`. It stays
  stable while the remote keeps the file name, never meets a native
  autoincrement id, and stays exact as a JSON number (< 2^53; a 63-bit value
  would lose precision in JavaScript). Attachments and the cleanup on event
  delete work unchanged.
TASK-12: `CalendarService` changes:
  - the three event listings share one private `collectEvents()`;
  - external calendars use the configured TTL (default 300 s) for every
    `calendar_events` entry;
  - an unreachable external calendar is left out, its instance id is
    collected (`unavailableCalendars()`), and it is flagged in `list()` for
    the TTL. Failures are never cached.

  `CalendarService` is bound `scoped` (`AppServiceProvider`), so the
  controller and widget service read the ids from the same instance that
  produced the listing. `IntegrationGateway`, `SendDailyDayRemindersCommand`
  and `RefreshCalendarCache` keep their return shapes; they just get no
  events from a failed calendar.
TASK-13: `CalendarResource` gains `is_external` and `is_unavailable`.
  `calendars/events/{yearMonth}`, `calendars/events/widget` and
  `widgets/your-day` return `meta.unavailable_calendars` (always present,
  possibly empty). New `EventListingMeta` schema in `OpenApiSpec.php`, and
  the annotations are updated. `bootstrap/app.php` maps the remote `412` →
  `412` and unavailable → `503`, and doesn't report either (the client
  already logs the host).
TASK-14: `CalendarImport` now uses `RemoteCalDavClient::discover()`
  (`/.well-known/caldav` → `current-user-principal` → `calendar-home-set` →
  collections, falling back to `principals/users/<username>/`), and fetches
  events through the client, so the import is SSRF-guarded too. The app
  password is stored in the import state encrypted (`Crypt`). The helper
  methods that discovery made dead (`parseCalendarsFromXml`,
  `parseCalendarHomeFromXml`, `getCalendarsXml`,
  `getCalendarHomeDiscoveryXml`) were removed with their unit tests.
TASK-15: `calendars/external/accounts` endpoints (list, store, update,
  destroy, `{account}/available`, `{account}/calendars`), handled by
  `ExternalCalendarController`, `ExternalCalendarService`, three
  FormRequests and two resources, with OpenAPI annotations.
  - An account is stored only if discovery finds calendars.
  - A password change is checked against the server first.
  - Connecting accepts only URLs that discovery listed (cached 10 min,
    without secrets) and de-duplicates names like the import.
  - Removing one calendar uses the existing `DELETE calendars/{id}`.
    Removing an account removes all its calendars.
  - Nothing is ever sent to the remote for these operations, and the
    password never comes back in a response.
TASK-16: `CalendarService::share/listSharees/revokeShare` throw
  `AuthorizationException` for external calendars → `403` through the
  central map.
TASK-17: user deletion needed no change. DAV runs first and removes the
  native row and the link; the users FK cascade takes the account. A test
  in `DeleteUserTest` covers it. The export **proxies**: external events are
  read from their server and written like native ones, and an unreachable
  external calendar is skipped (`CalendarExportService`).
TASK-18: `tests/Support/FakeCalDavServer.php` is a stateful fake remote
  behind `Http::fake`: objects, ETags, sync tokens, discovery, outages and
  stray hrefs. Helper `externalCalendar()` lives in `tests/Pest.php`.
  `tests/TestCase.php` binds the URL guard to a fixed public resolver, so no
  test does real DNS. New tests:
  - `app/Dav/Tests/ExternalCalendarProxyTest.php`: DAV read, list, create,
    update, delete; 412 (stale and racing); query; multiget; encoded names;
    non-child and other-host hrefs; sync passthrough and fallback; 503 for
    one collection; DAV sharing refused; collection delete; no
    `calendarobjects` rows; native calendars untouched.
  - `app/Api/Calendars/Tests/ExternalCalendarApiTest.php`: API CRUD, 412,
    outage reporting and recovery, 503, short TTL, attachments, sharing
    refused, occurrence edit with If-Match, and the whole connect/manage
    flow including SSRF, wrong password and isolation.
  - `app/Dav/Tests/RemoteCalDavClientTest.php`: auth, size cap, internal
    address, redirect hop, credentials absent from logs/caches/responses.
  - `tests/Unit/OutboundUrlGuardTest.php`.
  - Additions to `DavImportTest`, `CalendarExportServiceTest` and
    `DeleteUserTest`.
TASK-19: not done. It is a manual check against real Nextcloud, iCloud,
  Fastmail, Radicale and Baïkal accounts and needs a human (see below).

## Known issues / follow-ups

- **TASK-19 (human):** check per provider that names are kept on create,
  that hrefs are direct children, and that sync-collection works. iCloud
  puts the calendar home on another host than the principal. Discovery
  allows that, and the link pins hrefs to the collection's host.
- No new user-facing strings in notifications, mails or bot replies, so
  nothing for the `translator` agent. API messages are English per
  STANDARDS 7.4.
- `EventAttachmentService::deleteAllForEvent` deletes by event id without a
  user filter (existing behaviour). With hashed external ids, a cross-user
  collision is very unlikely (52-bit hash) but not impossible. Scoping
  that delete to the user would remove the risk; it's out of scope here.
- The frontend (separate repo) needs UI for the connect flow, and should
  show `is_unavailable` / `meta.unavailable_calendars`.
- In this worktree `.env.testing` is mounted as a character device, so
  `git add -A` fails on it. It was excluded from every commit and shows as
  modified in `git status`; that's environmental, not a change.
- Every test shows a `file_get_contents(/workspace/.env)` warning because
  the worktree has no `.env` (environmental, as the reviewer noted).

