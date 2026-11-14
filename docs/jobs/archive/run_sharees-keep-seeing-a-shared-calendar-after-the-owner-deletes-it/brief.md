# Brief: sharees keep seeing a shared calendar after the owner deletes it

type: feature
id: run
branch: feature/run_sharees-keep-seeing-a-shared-calendar-after-the-owner-deletes-it
date: 2026-11-14
author: Leander Muskalla

## What

When the owner deletes a shared calendar, only the owner's caches are
cleared:

- API: `CalendarService::destroy()`
  (`app/Api/Calendars/Services/CalendarService.php:110-115`) forgets the
  owner's calendar list and event caches and nothing else.
- DAV: `CacheInvalidationPlugin` looks up the calendar instance to find the
  sharees, but by then the instance rows are already deleted. The lookup
  returns null and the plugin returns early.

Sharees still see the deleted calendar in their calendar list for up to 24 h,
and its events for up to 7 days for past months.

This was the open item from the review of the shared-calendar cache
invalidation job (b15c9ec) and is still unfixed.

## Why

A calendar that no longer exists keeps showing up for everyone it was shared
with. Opening or editing its events will fail.

## Steps

1. Collect the sharees' user ids *before* the calendar is deleted, in both
   paths:
   - API: in `destroy()`, before `$this->dav->calendars()->delete()`.
   - DAV: in `beforeUnbind` (or the equivalent pre-delete hook) in
     `CacheInvalidationPlugin`, not after.
2. After the delete succeeds, clear each sharee's calendar list and that
   calendar's event caches. Reuse `InvalidateSharedCalendarCache` if it can
   take an explicit list of user ids instead of looking them up.
3. Tests: share a calendar, warm the sharee's caches, delete as owner (API
   and DAV), and confirm the sharee's list no longer contains it.
