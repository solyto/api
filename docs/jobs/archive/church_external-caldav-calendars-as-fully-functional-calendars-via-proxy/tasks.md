# Tasks: external CalDAV calendars as fully functional calendars, via proxy

id: church
analyst: @analyst
date: 2026-11-11

## ⚠ Blockers and open points (read first)

1. **The SSRF guard doesn't exist yet (brief step 1).** The brief makes it a
   separate prerequisite job. That job isn't in `docs/jobs/` and there's no
   outbound URL guard anywhere under `app/`. TASK-6 onward (everything that
   sends a request to a user-supplied host) is **blocked** until that job
   lands. TASK-1 to TASK-5 can go ahead. Don't build a local stand-in guard
   in this job unless the owner says so, because that would expand scope.
2. **Event attachment ids (brief step 3) need a decision.**
   `calendar_event_todos/notes.calendar_object_id` is `unsignedBigInteger`
   (`database/migrations/2026_06_02_000000_create_calendar_event_attachments_tables.php`).
   `EventDTO::$id` is `?int` (the pgsql `calendarobjects.id`). The API
   returns that value as `id`, and `CalendarController.php:458` passes
   `(string) $event->id` to `deleteAllForEvent`. External events have no
   local row, so `id` would be `null`. The frontend is in another repo, so we
   can't confirm from here which field it sends as `{eventId}`. TASK-2 only
   investigates and writes down the options. The owner picks one before
   TASK-11 is implemented.
3. **If-Match passthrough on DAV writes isn't something sabre provides.**
   sabre's backend `createCalendarObject`/`updateCalendarObject` signatures
   don't carry request headers. sabre's CorePlugin checks `If-Match` itself
   against the node's ETag, which for external calendars means a remote
   `GET` first. TASK-3 has to settle how the client's `If-Match` reaches the
   remote `PUT` (for example, the ETag sabre already validated, or a
   request-scoped value set by a small server plugin). It also has to settle
   how a remote `412` becomes a sabre `PreconditionFailed`. The API path
   already has an `etag` (`UpdateEventRequest`), so it's simpler.
4. **ctag/sync-token passthrough costs a remote call on every DAV home
   listing.** `getCalendarsForUser` / `getCalendarByIdCustom` build
   `getctag`/`sync-token` from the local row. Passing them through means a
   remote `PROPFIND` (Depth 0) per external calendar per home `PROPFIND`, and
   an outage there must not fail the listing (decision 12). TASK-3 confirms
   where this happens and whether it uses the 5 min cache.
5. ⚑ **pgsql / DAV flag (hard rule).** No pgsql schema change and no DAV
   route change. But `AppCalendarsPDO` (the DAV backend on the `pgsql`
   connection) gets a new kind of calendar with no objects, and DAV sharing
   is refused for it. Reviewers must check TASK-8 to TASK-10 against the
   existing DAV tests (`app/Dav/Tests/*`).
6. **Migrations must be shown before they run** (TASK-4). In an unattended
   run, the developer writes the migration files and doesn't run
   `php artisan migrate` outside the in-memory SQLite test suite.

## Task breakdown

TASK-1: Inventory every `AppCalendarsPDO` / sabre backend method that touches calendar objects or sync state, and confirm or extend the proxy table in the brief (no code).
  files: app/Dav/Backend/AppCalendarsPDO.php, vendor/sabre/dav/lib/CalDAV/Backend/PDO.php, vendor/sabre/dav/lib/CalDAV/{Calendar,CalendarObject,Plugin}.php, vendor/sabre/dav/lib/DAV/Sync/Plugin.php, vendor/sabre/dav/lib/DAV/CorePlugin.php, app/Dav/Services/{Events,Calendars}.php, app/Api/Export/Services/CalendarExportService.php; output appended to this job's implementation.md
  depends: none
  risk: low — read-only analysis, but it must also cover our own pgsql reads that bypass sabre (`getEventsCustom`, `getEventByUriCustom`, `getEventObjectsInRange`, `deleteEventCustom`'s re-read, `readRecurringObject`) and the export path.

TASK-2: Investigate event-attachment ids for external events and write down the options for the owner (open point 2), with no implementation.
  files: app/Api/Calendars/Services/EventAttachmentService.php, app/Api/Calendars/Controllers/{EventAttachmentController,CalendarController}.php, app/Api/Calendars/Models/{CalendarEventTodo,CalendarEventNote}.php, app/Dav/DTOs/EventDTO.php, app/Api/Calendars/Resources/EventResource.php, database/migrations/2026_06_02_000000_create_calendar_event_attachments_tables.php
  depends: none
  risk: medium — the right answer depends on what the separate frontend sends, which we can't see from this repo.

TASK-3: Design note for the three protocol details the brief leaves to implementation: If-Match/412 passthrough on DAV and API writes, sync-token encoding behind `http://sabre.io/ns/sync/` (plus no token when the remote lacks sync-collection), and how a DAV `503` is raised for one collection only.
  files: implementation.md (design section); reading app/Dav/Factories/DavServerFactory.php, app/Dav/Plugins/CacheInvalidationPlugin.php, vendor/sabre/dav/lib/DAV/{CorePlugin,Sync/Plugin}.php, vendor/sabre/dav/lib/CalDAV/Calendar.php
  depends: TASK-1
  risk: high — sabre's backend API has no request headers, so getting this wrong either loses remote-wins semantics (decision 6) or breaks native-calendar behaviour.

TASK-4: Migrations (shown, not run) and Eloquent models for the external account and external calendar tables on the default MariaDB connection.
  files: database/migrations/<new>_create_external_calendar_accounts_table.php, database/migrations/<new>_create_external_calendars_table.php, app/Api/Calendars/Models/ExternalCalendarAccount.php, app/Api/Calendars/Models/ExternalCalendar.php, app/Api/Calendars/Factories/ (new factories), app/Api/Users/Models/User.php (relation, only if the pattern exists there)
  depends: none
  risk: medium — accounts: user FK cascade, server URL, username, `encrypted` app password (precedent app/Plugins/Models/UserPlugin.php:33); calendars: account FK cascade, remote collection URL, pgsql `calendarid` + `instance_id` (no cross-DB FK possible, so cleanup must be explicit).

TASK-5: Config for the external-calendar proxy (remote timeout, default 5 s; event cache TTL, default 300 s; response size cap), read through `config()` per STANDARDS §12.5.
  files: config/calendars.php or an existing config file (match whatever the codebase already does), .env.example only if other config keys are listed there
  depends: none
  risk: low — new keys only, no behaviour change.

TASK-6: Remote CalDAV client class: Basic auth, timeout from config on connect and response, response size cap, SSRF guard on the base URL and on every returned href (host pinned to the account host), href ↔ object-name translation per decision 13 (non-child hrefs skipped and logged), and credentials kept out of logs, cache and exceptions.
  files: app/Dav/Services/RemoteCalDavClient.php (new; name to match Dav/Services conventions), app/Dav/Helpers/ICalendarHelper.php (reuse or extend the XML builders and parsers), app/Dav/Exceptions/ (new remote-unavailable / precondition exceptions)
  depends: TASK-5, SSRF guard job (blocker 1)
  risk: high — this is the security boundary for user-supplied hosts, and its XML parsing has to handle several providers.

TASK-7: Remote operations in the client: GET, PUT (If-Match / If-None-Match: *), DELETE, PROPFIND Depth 1 (object list), PROPFIND Depth 0 (ctag/sync-token), calendar-multiget, calendar-query with time-range returning calendar-data, and sync-collection.
  files: app/Dav/Services/RemoteCalDavClient.php, app/Dav/Helpers/ICalendarHelper.php
  depends: TASK-6, TASK-3
  risk: medium — straightforward HTTP work, but each REPORT body and response shape must match RFC 4791/6578.

TASK-8: Per-user cached lookup of external calendars in `AppCalendarsPDO`, plus routing of the object-level backend methods from TASK-1 (sabre overrides and our `*Custom` methods) to the remote client; native calendars stay on the existing code paths unchanged.
  files: app/Dav/Backend/AppCalendarsPDO.php, app/Dav/Services/{Calendars,Events}.php (constructor wiring only if needed), app/Dav/Factories/DavServerFactory.php (wiring only)
  depends: TASK-1, TASK-3, TASK-4, TASK-7
  risk: high — this class serves every DAV and API calendar read and write; a mistake in the external check affects native users.

TASK-9: Sync and ctag passthrough for external calendars in `getCalendarsForUser`, `getCalendarByIdCustom`, `getCalendarByNameCustom` and `getChangesForCalendar`, per the TASK-3 design; an unreachable remote yields no token rather than a failed listing.
  files: app/Dav/Backend/AppCalendarsPDO.php
  depends: TASK-8
  risk: high — a wrong token format makes DAV clients loop or resync everything.

TASK-10: DAV outage and sharing behaviour: `503` for an unreachable external collection only, and DAV sharing refused for external calendars (`updateInvites` / SharingPlugin path).
  files: app/Dav/Backend/AppCalendarsPDO.php, possibly a small plugin in app/Dav/Plugins/ (only if TASK-3 decides it's needed) and app/Dav/Factories/DavServerFactory.php
  depends: TASK-8
  risk: medium — touches DAV request handling (flag ⚑), but stays limited to external calendars.

TASK-11: Event-attachment ids for external events, implementing whatever the owner chose in TASK-2.
  files: per TASK-2 outcome (EventAttachmentService, EventDTO/EventResource, possibly a MariaDB migration shown first)
  depends: TASK-2 (owner decision), TASK-8
  risk: medium — can't be scoped until the decision is made.

TASK-12: `CalendarService`: 5 min TTL (from config) for every `calendar_events` cache entry of an external calendar, and per-calendar failure isolation in `listEvents`, `listWidgetEvents` and `listTodayEvents` that collects unavailable `instanceId`s instead of throwing (failures are never cached).
  files: app/Api/Calendars/Services/CalendarService.php, app/Dav/DTOs/CalendarDTO.php (an `isExternal` flag or similar)
  depends: TASK-5, TASK-8
  risk: medium — the return shapes of the three list methods change, and IntegrationGateway, SendDailyDayRemindersCommand and RefreshCalendarCache consume them (keep their behaviour; they just skip failed calendars).

TASK-13: API reporting: `CalendarResource` gains `is_external` and an availability field; event listings (`calendars/events/{yearMonth}`, `calendars/events/widget`, `widgets/your-day`, today) leave out failing calendars and add `meta.unavailable_calendars`; OpenAPI annotations updated.
  files: app/Api/Calendars/Resources/CalendarResource.php, app/Api/Calendars/Controllers/CalendarController.php, app/Api/Widgets/Controllers/WidgetController.php, app/Api/Widgets/Services/WidgetService.php, app/Shared/Services/IntegrationGateway.php, app/Api/ApiResponse.php (read only, `$meta` already exists)
  depends: TASK-12
  risk: medium — response-shape change for the separate frontend; must stay additive (STANDARDS §13).

TASK-14: Generic CalDAV discovery (`/.well-known/caldav` → `current-user-principal` → `calendar-home-set` → collections, falling back to `principals/users/<username>/`) shared by the import and the connect flow, and the plaintext app password removed from the import cache.
  files: app/Dav/Services/CalendarImport.php, app/Dav/DTOs/CalendarImportDTO.php, app/Dav/Helpers/ICalendarHelper.php, app/Api/Calendars/Jobs/ImportCalendars.php, app/Dav/Tests/DavImportTest.php
  depends: TASK-6
  risk: medium — changes the existing import flow; keep its behaviour and tests intact apart from discovery and the secret handling (the import's other bugs are out of scope).

TASK-15: "Connect external calendars" API: create account plus discovery, select collections (each creates a native pgsql calendar row plus a link row, with the name de-duplicated like the import), update the account's app password, remove a calendar (link + native row only), and remove an account (all its calendars that way). Requests, resources, routes and OpenAPI included.
  files: routes/api.php, app/Api/Calendars/Controllers/ExternalCalendarController.php (new), app/Api/Calendars/Requests/ (new requests), app/Api/Calendars/Resources/ (new account resource), app/Api/Calendars/Services/ExternalCalendarService.php (new), app/Api/Calendars/Services/CalendarService.php (destroy path for external calendars)
  depends: TASK-4, TASK-14
  risk: medium — new public endpoints taking user-supplied URLs and credentials; secrets must never come back in responses.

TASK-16: Refuse sharing external calendars through the API share endpoints (share / list sharees / revoke), with a proper error response.
  files: app/Api/Calendars/Controllers/CalendarController.php, app/Api/Calendars/Services/CalendarService.php, possibly a new exception in app/Api/Calendars/Exceptions/ + bootstrap/app.php mapping
  depends: TASK-8
  risk: low — guard clause only. Any new user-facing message is written in `en` and the other locales go to the translator agent.

TASK-17: Account deletion and other cleanup paths: make sure `Calendars::deleteForUser` / user deletion also removes external link rows (FK cascade from users) and the native rows. Confirm `CalendarExportService` either proxies external events or skips them, and record which.
  files: app/Dav/Services/Calendars.php, app/Api/Users/ (delete flow), app/Api/Export/Services/CalendarExportService.php, app/Api/Users/Tests/DeleteUserTest.php
  depends: TASK-8, TASK-15
  risk: low — mostly verification, with small changes if a gap shows up.

TASK-18: Tests with a fake remote CalDAV server (`Http::fake`): read, create, update, delete through `/api/v1/calendars…` and `dav/`; `412` passthrough; sync-token passthrough plus the fallback when sync-collection is missing; remote down (calendar left out plus reported in `meta`, DAV `503`, other calendars fine); non-child and other-host hrefs skipped; URL-encoded names round-trip; SSRF-blocked targets refused; no `calendarobjects` rows for the external `calendarid`; native calendars untouched; sharing refused (API and DAV); credentials absent from logs and cache.
  files: app/Dav/Tests/ExternalCalendarProxyTest.php (new), app/Api/Calendars/Tests/ExternalCalendarApiTest.php (new), docs/TESTING.md conventions
  depends: TASK-8 to TASK-17
  risk: medium — needs realistic multistatus fixtures; full `composer test` + `composer analyse` must exit 0.

TASK-19: Manual per-provider check (Nextcloud, iCloud, Fastmail, Radicale, Baïkal): names kept on create, hrefs are direct children, sync-collection support. Results go in implementation.md.
  files: implementation.md only
  depends: TASK-18
  risk: low — no code, but it needs real accounts, which an unattended agent doesn't have. This is a human task.

## Suggested order

TASK-1, TASK-2, TASK-4, TASK-5 can run in parallel → TASK-3 → (SSRF guard job)
→ TASK-6 → TASK-7 → TASK-8 → TASK-9, TASK-10, TASK-12, TASK-16 → TASK-13 →
TASK-14 → TASK-15 → TASK-17 → TASK-11 (after the owner's decision) → TASK-18 →
TASK-19 (human).
