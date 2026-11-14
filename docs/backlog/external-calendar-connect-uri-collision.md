# Backlog: connecting external calendars can fail with a 500 halfway through

created: 2026-11-14

## What

`ExternalCalendarService::connect()`
(`app/Api/Calendars/Services/ExternalCalendarService.php:121`) makes the
display name unique with `uniqueName()`. The DAV collection uri, though, is
`Str::slug(displayName)` (`app/Dav/Backend/AppCalendarsPDO.php:431`), and
`(principaluri, uri)` has a unique index
(`database/migrations/2025_12_08_161442_create_dav_tables.php:140`).

Cases that collide:
- A remote calendar "work" next to a local "Work". The names differ, but
  both slugs are `work`.
- Two names that slug to an empty string, such as CJK-only or emoji-only
  names.

The insert then hits the unique index, and the request returns 500. The
loop isn't in a transaction, so calendars connected earlier in the same
request stay connected.

## Why

A user connecting their Nextcloud or iCloud calendars gets an unexplained
error, and ends up with only some of the calendars connected.

## Steps

1. When a calendar is created, make the uri unique per principal: slug,
   falling back to a random/uuid uri if the slug is empty, plus a suffix on
   collision. Do this where the uri is generated, so native calendar
   creation benefits too, since it has the same latent problem.
2. Wrap the loop in `connect()` in a DB transaction. If the DAV tables are
   on a different connection than `external_calendars`, clean up the
   calendars already created when a later one fails.
3. Tests:
   - Connect "work" while a local "Work" exists.
   - Connect two emoji-only names.
   - A failure on the second calendar leaves nothing connected.
