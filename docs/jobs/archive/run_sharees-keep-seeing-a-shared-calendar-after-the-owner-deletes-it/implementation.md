# Implementation: sharees keep seeing a shared calendar after the owner deletes it

id: run
developer: developer
date: 2026-11-14

## Summary

When an owner deletes a shared calendar, the sharees' calendar list and event caches for that calendar are now cleared. This works in both paths: the API (`CalendarService::destroy()`) and DAV (`CacheInvalidationPlugin`). Both paths collect the sharee user ids before the delete, because Sabre removes all sharee instances along with the owner's instance. They clear the caches in the same request. `InvalidateSharedCalendarCache` is unchanged, for the reasons given in tasks.md.

## Changes

TASK-1: `app/Api/Calendars/Services/CalendarService.php`. `destroy()` now collects sharee ids through `sharing()->listSharees()` before `delete()`. After the delete it forgets `['calendars', $id]` and `forgetByPrefix(['calendar_events', $id, $calendarId])` for the owner and each sharee. This differs from tasks.md in one way: the guard is `! isShared && ! isExternal`, not `solytoShared`. `solyto_shared` only becomes true once a sharee has *accepted*. With the tasks.md guard, a calendar with only pending invites would leave those sharees' lists stale.

TASK-2: `app/Api/Calendars/Tests/CalendarApiTest.php`. Two tests in "Shared calendar cache invalidation":
- With an accepted and a pending sharee, an owner API delete removes the calendar from both sharees' lists and its events from the accepted sharee's month view.
- Deleting an unshared calendar leaves other users' list caches alone.

The first test fails without TASK-1.

TASK-3: `app/Dav/Plugins/CacheInvalidationPlugin.php`. Adds a `beforeUnbind` listener at the default priority, so it runs after the ACL check at priority 20. For `calendars/{email}/{uri}` paths whose instance has `access = 1`, it stores the sharee user ids (from every `access > 1` instance) under the path. `onAfterUnbind` takes them, forgets each sharee's list and event caches, and queues the delayed `RefreshCalendarCache`. It uses the plugin's existing try/catch and `dav` log style. The plugin is created once per DAV server (per request), so this state is not shared between requests. It only reads `pgsql`; the schema, connection and routes are unchanged.

TASK-4: `app/Dav/Tests/DavApiConsistencyTest.php`. Adds a `shareDefaultCalendar()` helper and four tests:
- End to end with the real cache: an owner DAV DELETE drops the calendar from both sharees' API lists.
- Spy: forgets each sharee's list and events and queues a refresh for each.
- A sharee deleting their own instance doesn't clear the owner's or the other sharee's list.
- A DELETE rejected by ACL (403) clears no sharee caches and queues nothing.

The two positive tests fail without TASK-3.

`composer test` and `composer analyse` both exit 0.

## Known issues / follow-ups

- In a fresh worktree, the first `composer test` run failed two `ExportTest` cases because `storage/app/public/user/` didn't exist yet. Reruns pass. This is about the environment, not this change. The tests could create the directory themselves.
- After an owner's DAV calendar delete, the owner's own event cache entries for that calendar are not cleared, because `invalidateCalendarEvents()` finds no instance after the unbind. This already happened before this change and is harmless: the calendar has left the owner's list, so those entries are never read again and simply expire.
