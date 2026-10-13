# Tasks: full user deletion routine

id: reduction
analyst: @analyst
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

## Flags for the developer / reviewer (read first)

1. **This job adds code inside `app/Dav` that writes rows on the `pgsql`
   connection** (new per-user deletion methods in `app/Dav/Services`). Per the
   project hard rule this is flagged: the `pgsql` connection config, the DAV
   schema, the existing backend methods and the DAV routes are **not** touched —
   only new service methods next to `Principals::create()`, using the patterns
   already in `app/Dav` (Sabre PDO via `DavService`, Eloquent models on
   `pgsql`, raw PDO inside `app/Dav` only). STANDARDS 5.5 / 12.6 forbid
   touching `pgsql` from `app/Api` — `UserService` must go through
   `DavService`, never the connection itself.
2. **Two databases, no cross-connection transaction.** App data is MariaDB,
   DAV data is PostgreSQL. Define an order (DAV first → non-cascaded app rows
   → user row → files → cache) so any failure aborts with the user row still
   intact and the command re-runnable.
3. **`UserService::destroy()` currently has zero callers** (the `users` api
   resource is `only(['index', 'update'])`, `routes/api.php:119`; no test uses
   it). It can be extended into the full routine — or a new method added —
   without breaking anything. No API endpoint is added in this job
   (brief asks for a CLI command only).
4. **Scope defaults decided by the analyst (unattended job) — reviewer should
   sanity-check them:**
   - `dev_requests.created_by_user_id` is **set to null** (anonymised), not
     deleted. It is the only user FK **without** `onDelete('cascade')`
     (`2026_01_29_183526_add_created_by_to_dev_requests.php:16`) and would
     block the user delete. `DevRequest` is shared community data by design
     (STANDARDS 12.3); deleting a user's dev requests would cascade-delete
     *other* users' comments and votes on them. Comments/votes by the user
     themselves cascade and are deleted.
   - Telegram `ConversationState` cache is **not** flushed: it has a 600 s TTL
     and self-expires (`app/Bots/State/ConversationState.php:32`), and flushing
     it would import `app/Bots` from the Users module against STANDARDS 12.8.
   - Explicitly **out of scope, record as follow-ups** (TASK-6): Pulse /
     Telescope rows referencing the user (observability, own pruning), pending
     queue jobs that carry a `User` model (STANDARDS 9.1 already lists the four
     jobs as backlog), orphaned global `feeds` rows the user created
     (`feeds.created_by` is a plain string, shared data), and any API
     self-service deletion endpoint.
5. Tests run both connections on SQLite (`tests/TestCase.php` remaps `pgsql`
   to a file-backed SQLite, DAV migration is driver-aware), the `user_data` /
   `private_user_data` cache stores map to `array` — everything below is
   testable without MariaDB/PostgreSQL/Redis.

## User-data inventory (verified 2026-10-13 — the basis of the tasks)

**Cascades automatically when the `users` row is deleted** (FK
`onDelete('cascade')`, default connection): todos (+subtasks, categories,
workspaces), tags, notes + note_categories (incl. journal category), app-side
calendars/calendar_entries/calendar_event_todos/calendar_event_notes/
calendar_event_attachments, address_books + contacts, feed_subscriptions,
friends + friend_requests, user_profiles, user_settings (incl. OpenAI token),
user_notification_settings, passkeys, telegram_bot_connections, ai_usage,
export_jobs, shortcuts, clipboards, finances (budgets, wealth fields/values),
time_tracking (projects/categories/entries), check_ins, all library tables
(books/movies/music/games/recipes/quotes/links/plants/genres/link categories),
dev_request_comments, dev_request_votes.

**Needs explicit deletion (no cascade):** `dev_requests.created_by_user_id`
(set null — see flag 4), `personal_access_tokens` (morph; `$user->tokens()`),
`notifications` (morph; `$user->notifications()`), `push_subscriptions`
(morph; `$user->pushSubscriptions()`), `verification_tokens` (no FK).

**DAV / `pgsql`, keyed by principal `principals/<email>`:** owned calendars +
calendarobjects + calendarchanges + calendarinstances (Sabre
`deleteCalendar` cascades), calendars **shared with** the user (instance rows
with `access > 1` — instance only, never the calendar), calendarsubscriptions,
schedulingobjects, addressbooks + cards + addressbookchanges (Sabre
`deleteAddressBook` cascades), propertystorage rows under
`calendars/<email>/…` and `addressbooks/<email>/…`, principal row +
`calendar-proxy-read`/`calendar-proxy-write` proxy principals + groupmembers
rows (created by Sabre's `createPrincipal`, which `Principals::create` calls).

**Files:** `Storage::disk('user_data')` directory `<user id>/` (profile
image, library covers, clipboard files, exports) and
`Storage::disk('private_user_data')` directory `<user id>/` (contact photos).
Nothing in `app/` writes the `dav` disk.

**Cache (Redis `user_data` store):** ~30 user-scoped `UserCacheService` keys
of the form `[CACHE_KEY, $user->id, …]` — enumerate at implementation time
with `grep -rn "private const string CACHE_KEY" app/Api` (todos, todos_due,
todo_categories, todo_workspaces, tags, shortcuts, notes, notes_newest,
note_categories, clipboard, checkin, tt_entries, tt_categories, tt_projects,
books, book_recommendations, movies, movie_releases, games, music,
music_recommendations, links, plants, recipes, quotes, budgets, wealth_fields,
calendars, calendar_events, address_books, contacts, user_feeds,
event_attachment_todos, event_attachment_notes, …). Two-part keys via
`forget`, three-part via `forgetByPrefix`. `weather_forecast` is not
user-scoped; `feed_items` is keyed by feed id (shared).

## Task breakdown

TASK-1: Add a `delete` capability to the DAV principals service that removes a
user's principal row, its `calendar-proxy-read`/`calendar-proxy-write` proxy
principals and the matching `groupmembers` rows on `pgsql`.
     files: app/Dav/Services/Principals.php (new method, mirroring
            `create()`); app/Dav/Models/Principal.php if used; covered later
            by TASK-5
     depends: none
     risk: medium — writes to the `pgsql` principals/groupmembers tables; must
            undo exactly what Sabre's `createPrincipal` created (verify against
            `vendor/sabre/dav` once dependencies are installed; Sabre's PDO
            principal backend ships no deletePrincipal, so this is new SQL in
            the established app/Dav pattern).

TASK-2: Add per-user DAV data deletion in `app/Dav/Services`: delete the
user's owned calendars (via existing `calendars()->delete`, which cascades
objects/changes/instances), delete only the instance rows of calendars shared
*with* the user (`access > 1`, pattern of `unshareCalendar`,
`AppCalendarsPDO.php:472-476`), delete their address books
(`addressBooks()->delete`), and clear `calendarsubscriptions`,
`schedulingobjects` and `propertystorage` rows for the principal
`principals/<email>` (DavHelper::getPrincipalUri).
     files: app/Dav/Services/DavService.php, app/Dav/Services/Calendars.php,
            app/Dav/Services/AddressBooks.php; likely uses
            app/Dav/Models/{CalendarInstance,CalendarSubscription}.php and
            `DB::connection('pgsql')` inside app/Dav only
     depends: TASK-1
     risk: high — the owned-vs-shared distinction is the one place this job
            can destroy another user's data: `calendars()->list($user)`
            returns shared instances too, and passing a shared calendar to
            `deleteCalendarCustom` would delete the owner's calendar (same
            latent hazard exists in `app:dav:reset-user` — do not reuse its
            loop blindly).

TASK-3: Implement the full deletion routine in `UserService` (extend the
currently unused `destroy()` or add a sibling): call the TASK-2 DAV cleanup
through `DavService`, then on the default connection set
`dev_requests.created_by_user_id` to null and delete
`personal_access_tokens`, `notifications`, `push_subscriptions` and
`verification_tokens`, delete the user row (FK cascades handle the rest,
inside `DB::transaction`), then `deleteDirectory($user->id)` on the
`user_data` and `private_user_data` disks, then flush the user-scoped
`UserCacheService` keys from the inventory above. Ordering per flag 2.
     files: app/Api/Users/Services/UserService.php (primary); no changes to
            app/Shared/Services/UserCacheService.php (forget/forgetByPrefix
            already suffice)
     depends: TASK-2
     risk: medium — orchestrates two databases with no cross-connection
            transaction (failure ordering must leave the user re-runnable) and
            a long explicit list (missing one store silently leaves data
            behind; TASK-5/TASK-6 are the safety net).

TASK-4: Add the artisan command `app:users:delete {email}` in the Users
module: look up by email, print a summary (id, name, email, role), require
`confirm()` before deleting, report what was removed step by step (pattern of
`app:dav:reset-user`), error out when the user is unknown, delete nothing
when confirmation is declined.
     files: app/Api/Users/Commands/DeleteUser.php (new — the Commands dir is
            already registered in bootstrap/app.php:38, no bootstrap change)
     depends: TASK-3
     risk: low — thin wrapper over the service following two existing command
            patterns (`app:dav:reset-user`, `app:send-test-notification`).

TASK-5: Test the routine and the command: one happy-path case seeding
representative data in every store family (a todo, note, feed subscription,
app contact + address book, DAV calendar with an event + DAV address book with
a card, telegram connection, sanctum token, push subscription, database
notification, verification token, a dev request by the user with someone
else's vote/comment, files on both disks, a cached entry) then assert each is
gone after the command (user row, DAV rows incl. principal + proxies,
dev_requests attribution nulled, `Storage::assertMissing`, cache keys absent);
plus negative cases (unknown email, declined confirmation leaves everything
untouched) and the two shared-calendar safety cases (deleting the sharee
keeps the owner's calendar and objects; deleting the owner removes the
sharee's instance too).
     files: app/Api/Users/Tests/CommandTest.php (extend the existing
            describe blocks) and/or a new
            app/Api/Users/Tests/DeleteUserTest.php; possibly
            app/Dav/Tests/DavServiceTest.php for the shared-calendar cases
     depends: TASK-3, TASK-4
     risk: medium — fixtures span both SQLite connections, both storage disks
            and the cache array store; setting up a calendar share in a test
            is the fiddly part (`CalendarSharing` / direct
            `calendarinstances` rows via the pgsql-remapped models).

TASK-6: Audit the finished routine against the inventory above and record the
result: re-run the greps (`->on('users')` in database/migrations,
`Storage::disk(` in app, `private const string CACHE_KEY` in app/Api, pgsql
table list in `2025_12_08_161442_create_dav_tables.php`), confirm every store
is covered or explicitly excluded, and write the deliberate exclusions and
follow-ups (Pulse/Telescope, pending User-carrying queue jobs, orphaned
`feeds.created_by`, ConversationState TTL, no API endpoint) into
implementation.md "Known issues / follow-ups".
     files: docs/jobs/reduction_full-user-deletion-routine/implementation.md
            (prose only, no code changes)
     depends: TASK-3 (best done after TASK-5)
     risk: low — documentation pass; its value is making the boundary of
            "everything" reviewable.

## Open questions (not blocking; defaults chosen, see flag 4)

- Dev requests: anonymise attribution (default) or delete the user's dev
  requests entirely (destroys other users' comments/votes on them)?
- Should Pulse/Telescope purge for the user become a follow-up job, or is
  observability data acceptable to keep (it is pruned by its own retention)?
- If an API self-service "delete my account" endpoint is wanted later, it
  should call the same service routine — deliberately out of scope here.
