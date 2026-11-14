# Tasks: sharees keep seeing a shared calendar after the owner deletes it

id: run
analyst: analyst
date: 2026-11-14

Branch verified: worktree HEAD is `feature/run_sharees-keep-seeing-a-shared-calendar-after-the-owner-deletes-it`, which matches `brief.md`.

## Findings that shape the plan

- **Event cache keys** are `['calendar_events', $userId, $calendarId]`, and every instance (owner and sharees) has the same `calendarId`. **Calendar list keys** are `['calendars', $userId]`. `revokeShare()` already clears a sharee this way, so the same two-key pattern works for delete.
- When the owner deletes the calendar (owner instance, `access = 1`), `AppCalendarsPDO::deleteCalendarCustom()` → Sabre `deleteCalendar` removes the calendar and **all** instances. When a sharee deletes their instance, only that instance goes. So we only need to collect sharees when the deleted instance is the owner's.
- **Which sharees to clear:** `CalendarSharing::listMemberIds()` returns the owner plus *accepted* sharees only. But a sharee's calendar list also shows *pending* invites (`collectEvents()` filters `inviteStatus === 'pending'` out of `list()`), so pending sharees must have their list cleared too. Use every `access > 1` instance: `CalendarSharing::listSharees()` → `getCalendarSharees()`, field `user_id`. Call it through `$this->dav->calendars()->sharing()`, not `CalendarService::listSharees()`, which throws for external calendars.
- **Why `InvalidateSharedCalendarCache` is not reused** (brief step 2 says "if it can"; it can't cleanly):
  1. It is `ShouldBeUniqueUntilProcessing` with `uniqueId = calendarId`. If an event-write run for the same calendar is still pending (the DAV plugin delays it 10 s), a delete run would be silently dropped. That pending run then finds no instances and does nothing.
  2. It never clears the calendar *list* cache, which is the main symptom.
  3. Clearing in the same request, before the response goes out, also closes the window where a sharee reloads before a queued job runs.

  So both paths clear the caches directly in the request, the same way `revokeShare()` / `unsubscribe()` and the plugin's `invalidateCalendarsForEmail()` already do. The job stays as it is.
- **DAV hook order:** Sabre's ACL plugin checks permissions on `beforeUnbind` at priority 20. The new listener must use the default priority (100) or later, so a rejected DELETE never reaches it. `beforeUnbind` also fires for MOVE, and `afterUnbind` does not fire if the delete fails. So the collected ids must be stored per path and only used up in `onAfterUnbind`.

## Task breakdown

TASK-1: In `CalendarService::destroy()`, when the calendar is the owner's (`! $calendar->isShared`) and shared (`$calendar->solytoShared`), collect the sharees' user ids via `$this->dav->calendars()->sharing()->listSharees($calendar)` *before* `delete()`. After the delete, forget each sharee's `['calendars', $id]` and `forgetByPrefix(['calendar_events', $id, $calendarId])`.
  files: app/Api/Calendars/Services/CalendarService.php
  depends: none
  risk: low — one method, using the cache pattern `revokeShare()` already uses; the guard keeps a sharee's own delete and unshared/external calendars unchanged.

TASK-2: Add API tests to `CalendarApiTest`:
  (a) Share a calendar with an accepted sharee and a pending sharee, warm both sharees' `GET /api/v1/calendars` (and the accepted sharee's events month), delete it as the owner via the API, then assert neither sharee's list contains it and the accepted sharee's events no longer include its events.
  (b) A delete of an unshared calendar still clears only the owner's caches.
  files: app/Api/Calendars/Tests/CalendarApiTest.php
  depends: TASK-1
  risk: low — test-only; uses the existing sharing helpers (`inviteUser` / `acceptInvite`), as `InvalidateSharedCalendarCacheTest` does.

TASK-3: In `CacheInvalidationPlugin`:
  - Register a `beforeUnbind` listener (default priority, after ACL).
  - For a calendar collection path (`calendars/{email}/{uri}`, 3 parts) whose `pgsql` `calendarinstances` row has `access = 1`, collect the user ids of every `access > 1` instance of that `calendarid` and store them by path.
  - In `onAfterUnbind`, take the stored ids for that path. For each sharee, forget `['calendars', $id]` and `forgetByPrefix(['calendar_events', $id, $calendarId])`, and queue the delayed `RefreshCalendarCache` the same way `invalidateCalendarsForEmail()` does.
  - Keep the plugin's existing try/catch + `dav` log channel style.
  files: app/Dav/Plugins/CacheInvalidationPlugin.php
  depends: none
  risk: medium — it adds a new Sabre hook into every DAV DELETE/MOVE. Wrong priority or path handling could leak state between requests or run for a request ACL rejected. It reads `pgsql` but does not change the DAV schema, connection or routes.

TASK-4: Add DAV tests to the `CacheInvalidationPlugin` / "Calendars: DAV and API agree" blocks in `DavApiConsistencyTest`:
  (a) The owner shares a calendar (accepted + pending sharee). The sharees' API caches are warmed, then the owner sends a DAV `DELETE calendars/{owner}/{uri}/`. Assert the sharees' `GET /api/v1/calendars` no longer contains it.
  (b) A sharee DAV-deleting their own instance does not clear the owner's or other sharees' caches through the new path.
  (c) A DELETE that ACL rejects (another user, as in `DavIsolationTest`) clears no sharee caches.
  files: app/Dav/Tests/DavApiConsistencyTest.php
  depends: TASK-3
  risk: low — test-only. Note that the plugin block's `beforeEach` swaps in a cache spy and fakes the queue, so the end-to-end check (a) belongs in a block that uses the real cache.

## Out of scope / notes

- Not changed: `InvalidateSharedCalendarCache` (see the reasons above). `ExternalCalendarService::destroyAccount()` also calls `destroy()`, but external calendars can't be shared, so the guard skips it.
- A sharee deleting their instance over DAV (not via the API `unsubscribe`) doesn't clear the owner's list cache (`solytoShared` flag). That is existing behaviour and not part of this brief.
- No user-facing strings, migrations or dependencies are involved.
