# Implementation: full user deletion routine

id: reduction
developer: @developer
date: 2026-10-13

<!-- Produced by @developer after implementation. -->

## Summary

A full per-user deletion routine that removes every user data store of a
user — DAV (PostgreSQL), app data (MariaDB cascades + morph rows + the
FK-less stores + dev request attribution), files (both per-user disks) and
the user-scoped cache (including the `longterm` store) — plus an artisan
command `app:users:delete {email}` with summary and confirmation, and tests
covering the happy path, the negatives and the two shared-calendar safety
cases. All six tasks completed. The review rework round added the two
FK-less stores the audit greps could not see (`password_reset_tokens`,
`sessions.user_id` — both deleted in the routine now) and rerouted the three
`*_release_notified` cache keys through the `longterm` store they actually
live in.

## Changes

TASK-1: `app/Dav/Services/Principals.php` — new `delete(string $email): bool`
mirroring `create()`: removes the principal row plus the
`calendar-proxy-read`/`calendar-proxy-write` proxy principal rows and every
`groupmembers` row referencing those ids (both `principal_id` and `member_id`
directions), via raw PDO on the connection `DavService` already hands in,
using the Sabre backend's public `$tableName`/`$groupMembersTableName`.
Verified against `vendor/sabre/dav` 4.7.0 (installed): Sabre's
`createPrincipal` writes only the single principal row (no proxies, no
groupmembers — checked in vendor source and empirically); proxies are
seeded only for `principals/admin` by the DAV migration, but a client could
create them for a user via DAV MKCOL, so they are deleted defensively.
Returns `false` when no principal row exists (new users do not get one —
`UserObserver` creates calendar + address book but not a principal).

TASK-2: per-user DAV deletion:
- `app/Dav/Backend/AppCalendarsPDO.php` — `deleteSharedInstancesCustom(string
  $principalUri): int`, the `unshareCalendar` WHERE shape for all shared
  instances at once (`principaluri = ? AND access > 1`).
- `app/Dav/Services/Calendars.php` — `deleteForUser(User $user): array`
  (`['owned' => int, 'shared' => int]`): shared instance rows go first, then
  owned calendars through the existing `delete()` (Sabre `deleteCalendar`
  cascades objects/changes/all instances incl. the sharees' instances). The
  `isShared` check in the loop is a second guard — `list($user)` returns
  shared instances too and `deleteCalendarCustom` on a shared instance would
  destroy the owner's calendar (the `app:dav:reset-user` latent hazard,
  which is *not* reused blindly).
- `app/Dav/Services/AddressBooks.php` — `deleteForUser(User $user): int`
  via the existing `delete()` (Sabre `deleteAddressBook` cascades cards and
  addressbookchanges).
- `app/Dav/Services/DavService.php` — `deleteForUser(User $user): array`:
  orchestrates the above, deletes `calendarsubscriptions` (Eloquent
  `CalendarSubscription::forPrincipal`), `schedulingobjects` and
  `propertystorage` rows for `principals/<email>` (path prefixes
  `calendars/<email>` and `addressbooks/<email>`) via `DB::connection('pgsql')`
  — allowed inside `app/Dav` only — and finally TASK-1's principal delete.
  Returns a summary array the command prints. No `pgsql` config, schema,
  backend method or route was touched.

TASK-3: `app/Api/Users/Services/UserService.php` — the currently unused
`destroy()` became the full routine, ordered DAV first → dev request
attribution anonymised + morph rows deleted + user row deleted (one
`DB::transaction` on the default connection) → both per-user storage
directories → cache flush. `dev_requests.created_by_user_id` is set to null
(only FK to `users` without cascade; dev requests are shared community data
per STANDARDS 12.3 — deleting them would cascade other users'
comments/votes, which belong to those users). Morph rows: personal access
tokens, database notifications, push subscriptions via the existing
relations; verification tokens have no FK and are deleted directly, as are
the FK-less `password_reset_tokens` (keyed by email) and `sessions` (keyed
by `user_id`) rows. The cache flush enumerates the 32 two-part
`[key, user id]` keys on the `user_data` store and the 3
`*_release_notified` keys on the `longterm` store (exact `forget` — the
Grab*Releases jobs keep those forever in `longterm`, so they are forgotten
through a `UserCacheService('longterm')` instance), plus the 4 three-part
`[key, user id, resource id]` keys (`forgetByPrefix`, `user_data`), verified programmatically against every
`private const string CACHE_KEY` in `app/Api`/`app/Dav` — 41 constants =
39 covered + `weather_forecast` (keyed by location) and `feed_items`
(keyed by feed id), both deliberately excluded as shared. `UserCacheService`
itself was not touched (per tasks).

TASK-4: `app/Api/Users/Commands/DeleteUser.php` — new
`app:users:delete {email}`: prints id/name/email/role table, requires
`confirm()` (declines delete nothing, safe default no), errors with exit 1
on unknown email, and reports each step from the summary (owned calendars,
shared instances, address books, subscriptions, scheduling objects,
property rows, principal, anonymised dev requests, tokens, notifications,
push subscriptions, verification tokens, file directories, cache keys).
Thin wrapper over `UserService` following the `app:send-test-notification` /
`app:dav:reset-user` patterns; no bootstrap change (the Users Commands dir
is already registered).

TASK-5: tests:
- `app/Api/Users/Tests/DeleteUserTest.php` (new) — happy path seeds every
  store family (todo, note, feed subscription, telegram connection, dev
  request by the user with another user's vote and comment, database
  notification, sanctum token, push subscription, verification token, DAV
  principal + proxy + groupmembers rows, DAV calendar with an event, DAV
  address book with a card, scheduling object, calendar subscription,
  propertystorage row, app-side address book + contact, a
  `password_reset_tokens` row, a `sessions` row, files on both disks,
  cached entries incl. a `longterm` release-notified key) and asserts each
  is gone after the command (user row, all DAV rows incl. principal/proxies/
  groupmembers, dev request attribution nulled while the request + the
  other user's vote/comment survive, `Storage::assertMissing`, cache keys
  absent while another user's key survives). Negatives: unknown email exits
  1; declined confirmation leaves user, token, default calendar and file
  untouched.
- `app/Dav/Tests/DavServiceTest.php` — two shared-calendar safety cases:
  deleting the sharee removes only the sharee's instance row (owner's
  calendar, objects, instances and principal survive); deleting the owner
  removes the calendar entirely (all instances, objects, the calendars row)
  while the sharee's own default calendar survives.

TASK-6: this audit. The prescribed greps re-run:
- `->on('users')` in `database/migrations` — exactly one non-cascade FK
  (`dev_requests.created_by_user_id`, anonymised); every other users FK
  cascades, so the user row delete covers todos (+ subtasks/categories/
  workspaces), tags, notes + note categories, app-side calendars/entries/
  event todos/notes/attachments, app-side address books + contacts,
  feed_subscriptions, friends + friend requests, user profiles/settings/
  notification settings, passkeys, telegram bot connections, ai usage,
  export jobs, shortcuts, clipboards, finances, time tracking, check-ins,
  all library tables, the user's own dev request comments/votes, and the
  legacy `nextcloud_*` tables.
- morph tables: `personal_access_tokens`, `notifications`,
  `push_subscriptions` (deleted explicitly), plus the FK-less tables the
  users-FK grep cannot see: `verification_tokens` (by `user_id`),
  `password_reset_tokens` (by email) and `sessions` (by `user_id`) — all
  deleted explicitly.
- `Storage::disk(` in `app/` (non-test): `user_data` and
  `private_user_data` (per-user directories `<user id>/`, both deleted),
  `public` (dev request screenshots `dev-requests/<id>/` — community data
  belonging to the surviving dev request, deliberately kept), and the two
  image drivers which operate on disks passed in (covered via the
  directories above). Nothing in `app/` writes the `dav` disk.
- pgsql table list of `2025_12_08_161442_create_dav_tables.php` — covered:
  calendars, calendarobjects, calendarinstances, calendarchanges,
  addressbooks, cards, addressbookchanges, calendarsubscriptions,
  schedulingobjects, propertystorage, principals, groupmembers. Excluded on
  purpose: `locks` (path-keyed, no principal/user column, pruned by
  timeout) and the DAV `users` digest table (`LaravelAuthBackend`
  authenticates against Laravel users on the default connection — no
  per-user rows exist; only the seeded `admin`).
- cache key constants: 41 distinct — 39 covered (36 on `user_data`, 3
  `*_release_notified` on `longterm`) + `weather_forecast` + `feed_items`
  (see TASK-3).

Environment for verification: `composer install` from the committed lock
file (no new packages), plus the gitignored `storage/framework/*` skeleton
dirs; everything verified against the tests' in-memory SQLite (both
connections), never against MariaDB/PostgreSQL/Redis. Full suite green:
703 passed (warnings are the missing `.env` file in this checkout, present
on every test including untouched ones).

## Known issues / follow-ups

- Pulse / Telescope rows referencing the user are **not** deleted
  (observability data, pruned by their own retention). A purge job could be
  a follow-up if the owner wants it.
- Pending queue jobs that carry a `User` model
  (`RefreshCalendarCache`, `ImportCalendars`, `RefreshContactsCache`,
  `ImportContacts` — already STANDARDS 9.1 backlog) run on the stale row if
  they execute after the deletion; they repopulate a TTL cache key for the
  dead user id instead of failing. Fixing them to carry ids is the existing
  backlog item; until then, a just-deleted user's cache keys can reappear
  for up to their TTL (≤ 24 h). Not observable as data.
- Orphaned global `feeds` rows: `feeds` are shared rows (ownership lives in
  per-user `feed_subscriptions` since migration `2026_02_20_134424`);
  deleting the last subscriber leaves the feed row and its items in place,
  and `feeds.created_by` keeps the creator's email as a plain string.
  A follow-up job could drop feeds with zero subscriptions and null
  `created_by` on user deletion.
- Telegram `ConversationState` cache is not flushed: 600 s TTL,
  self-expires, and flushing it from the Users module would import
  `app/Bots` against STANDARDS 12.8.
- `weather_forecast` (keyed by location) and `feed_items` (keyed by feed id)
  cache entries are shared and deliberately survive.
- Dev request screenshots on the `public` disk (`dev-requests/<id>/`)
  survive with their dev request — community data, per the anonymisation
  decision.
- No API self-service "delete my account" endpoint was added (brief asked
  for a CLI command only); if wanted later it should call the same
  `UserService::destroy()`.
- Two of ~12 full-suite runs showed the known flaky, order-dependent failure
  in `App\Api\Export\Tests\ExportTest` (CSV export path, already documented
  in job `symbol_time-tracking-improvements`); it passes in isolation (6
  consecutive runs), on every subsequent full-suite run (9 consecutive),
  and its code path shares nothing with this branch (`UserService` has no
  caller in `app/Api/Export`; the DAV additions are new methods).
- Pre-existing style debt flagged by `pint --test` on touched files
  (`class_attributes_separation` property blocks, unused Sabre imports in
  `DavService.php`, unused `User` import in `DavServiceTest.php`,
  `!$x`/`! $x` mix) predates this branch and was left untouched per
  STANDARDS 11.1 ("match the file you are in") and OQ-6 (Pint not adopted).
