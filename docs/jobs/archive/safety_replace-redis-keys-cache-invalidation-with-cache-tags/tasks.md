# Tasks: Replace Redis KEYS cache invalidation with cache tags

id: safety
analyst: analyst
date: 2026-11-23

## Findings that shape the plan

- **Laravel 12.70 tags.** A tagged entry's key is `sha1(tag namespace):key`, and the namespace depends on the tags and their order. A read, write or `forget` must therefore pass exactly the same tag list as the write did. Flushing any one tag drops every entry that carries it: on Redis through the tag's entry set, on the array store by rotating the tag id. So a per-user tag plus a per-user-per-calendar tag covers both flushes the brief needs.
- **Tags must stay per user per calendar.** Every current invalidation is `[calendar_events, <userId>, <calendarId>]`. Each user's events are cached separately, and the sharee job flushes each member one by one. Use the tags `user_<userId>` and `calendar_events_<userId>_<calendarId>`, so every flush clears exactly what the prefix delete clears today. A global per-calendar tag would change which caches get cleared, so it is out of scope.
- **`collectEvents` needs no batched read.** It does one `remember` (native calendars) or one `get` plus `store` (external calendars) per calendar and never calls `many()`. Don't add a tagged `many`.
- **The brief's method list is missing a tagged `forget`.** `contacts_v2` and the attachment keys are cleared with exact `forget` calls everywhere except at account deletion:
  - `ContactService.php:78,131,140,149,160,170`
  - `ContactImport.php:162`
  - `CacheInvalidationPlugin.php:267`
  - `EventAttachmentService.php:70,82,102,114,121-122`

  Once these keys are tagged, an untagged `forget` silently misses them, so `UserCacheService` needs a tagged `forget` as well.
- **Tag lists live on the owning service.** Build each tag list in one public static method there:
  - `CalendarService` for event tags. The job, `CacheInvalidationPlugin` and `CalendarImport` already reference `CalendarService` or duplicate its constant.
  - `ContactService` for contact tags. Used by `ContactImport` and the plugin.
  - `EventAttachmentService` for attachment tags. Used only internally.

  The duplicated `CACHE_KEY_EVENTS` and `CACHE_KEY_CONTACTS` constants in `CalendarImport` and the plugin should use the owner's tag and key helpers instead, so keys and tags can't drift.
- **`UserService::forgetUserCache` returns a count** that ends up in `cache_keys_flushed` and in the admin `DeleteUser` command's output (`DeleteUser.php:56`). No test asserts it. Keep the number unchanged; the brief says nothing visible may change.
- **Schedule `cache:prune-stale-tags` with the store argument `user_data`.** The default store is `redis`. It shares connection and prefix with `user_data`, but naming the store makes the intent explicit. Scheduling doesn't run the command in tests, so the array store isn't a problem.
- **No Redis in the container** (see the report). Brief item 3 (real Redis, `INFO commandstats`) may not be doable here. See TASK-12.

## Task breakdown

TASK-1: Move the prefix-delete double from `CalendarApiTest.php:663-679` into one shared test helper, and bind it in every test file that TASK-2 to TASK-5 add tests to. It's needed for the before state only; TASK-11 deletes it.
  files: tests/Support/ (new helper, next to FakeCalDavServer), app/Api/Calendars/Tests/CalendarApiTest.php
  depends: none
  risk: low — test-only; the existing shared-calendar tests must still pass unchanged.

TASK-2: Add behaviour tests for the API event writes. For each case, read the month, today and widget listings through the API, write, then read again and assert the change shows. Cover:
  - event create, update, delete
  - move between calendars (both calendars)
  - occurrence update and delete
  - calendar recolour (update), and calendar delete (`destroy`)

  Also add one test that a second unchanged read is a cache hit. For example, assert that the DAV `listExpanded` isn't called again, or that a direct DAV change stays invisible until a write.
  files: app/Api/Calendars/Tests/CalendarApiTest.php (or a new CalendarCacheBehaviourTest.php in the same dir)
  depends: TASK-1
  risk: medium — the tests must fail if invalidation is removed; check each one by temporarily commenting out the flush, then restore it.

TASK-3: Add behaviour tests for sharing:
  - share/accept, then a write by the owner shows for the sharee, and a write by the sharee shows for the owner (run `InvalidateSharedCalendarCache` sync or drain the queue)
  - revoke clears the recipient's events
  - unsubscribe clears the sharee's events
  - owner calendar delete clears the sharees' events

  Convert `InvalidateSharedCalendarCacheTest` to also assert behaviour (member sees the change, outsider's cache untouched), not only the spy.
  files: app/Api/Calendars/Tests/CalendarApiTest.php ("Shared calendar cache invalidation" block), app/Api/Calendars/Tests/InvalidateSharedCalendarCacheTest.php
  depends: TASK-1
  risk: medium — queued, delayed jobs (10 s delay from DAV) must be driven explicitly in the test.

TASK-4: Add behaviour tests for CalDAV writes, where the API read reflects the change:
  - CalDAV PUT (create and overwrite) and DELETE of an event
  - DELETE of a whole calendar collection, including its sharees (`collectSharees`/`invalidateSharees`)
  - a CalDAV write to a shared calendar reaching the sharee after the job runs

  Also add a CardDAV PUT/DELETE test whose API contacts read reflects the change, so the `contacts_v2` forget path is covered before it is tagged.
  files: app/Dav/Tests/DavApiConsistencyTest.php
  depends: TASK-1
  risk: medium — DAV tests need the plugin's `UserCacheService` to be the bound double. Check how the DAV server resolves it before relying on the binding.

TASK-5: Add behaviour tests for the remaining paths:
  - calendar import: `CalendarImport::clearCache` after `importEvents`, then the API read shows the imported events
  - external calendars: `forgetRemoteState` and the 412/unreadable stale-drop in `forgettingStaleEvents` (`ExternalCalendarApiTest.php:116-145,234-235`), with the reload showing the remote's version
  - `EventOccurrenceBoundsMigrationTest`'s cache assertions as behaviour
  - event attachment attach/detach for todos and notes, then the read shows the change
  - account deletion: populate `calendar_events`, `contacts_v2`, `event_attachment_todos` and `event_attachment_notes` through the real services, delete the account, and assert none of them is served any more while another user's entries survive

  For the account deletion test, assert via the owning services' read methods against the deleted `User` instance (stale cache vs. recomputed empty result). Raw key checks depend on the key layout, so avoid them; this way the assertions don't change after the switch.
  files: app/Dav/Tests/ (import test), app/Api/Calendars/Tests/ExternalCalendarApiTest.php, app/Dav/Tests/EventOccurrenceBoundsMigrationTest.php, app/Api/Calendars/Tests/ (attachment test), app/Api/Users/Tests/DeleteUserTest.php
  depends: TASK-1
  risk: medium — the external calendar and import paths need `FakeCalDavServer`/`Http::fake`; reuse the existing setups rather than building new ones.

  Gate: run `composer test` on the unchanged implementation and confirm TASK-2 to TASK-5 pass before any task below starts.

TASK-6: Add tagged methods to `UserCacheService`:
  - `getTagged`, `storeTagged`, `rememberTagged` and `forgetTagged`, each taking `array $tags, array $identifiers`
  - `flushTags(array $tags)`

  Keep the current semantics: a falsy value is a miss, a non-positive TTL means `forever`, and failures are logged to the `cache` channel with the key/tags only. Add unit tests next to the existing ones.
  files: app/Shared/Services/UserCacheService.php, tests/Unit/UserCacheServiceTest.php
  depends: gate after TASK-5
  risk: medium — `flushTags` must catch `\Throwable` like `forgetByPrefix` does, so a Redis failure never breaks a write. Check the exception types that `Cache::tags()` can throw on a non-tagging store.

TASK-7: Switch all calendar event caching to tags in one commit:
  - Add `CalendarService::eventCacheTags(string $userId, int $calendarId): array`, returning `['user_<id>', 'calendar_events_<id>_<cal>']`.
  - Use it for the `remember`, `get` and `store` calls in `collectEvents` (`:249-279`).
  - Replace every `forgetByPrefix` in `CalendarService` (`:106,142,226,311,334-335,364,374,384,394,415,450,470`) with `flushTags`.
  - Do the same in `InvalidateSharedCalendarCache::handle` (`:37`), `CacheInvalidationPlugin` (`:148,214`) and `CalendarImport::clearCache` (`:172`). Drop the duplicated `CACHE_KEY_EVENTS` constants there.

  The flush can be the calendar tag alone, but it must clear the same user's entries. Commit everything together: switching reads without the other call sites would make the TASK-3/4/5 tests go red.
  files: app/Api/Calendars/Services/CalendarService.php, app/Api/Calendars/Jobs/InvalidateSharedCalendarCache.php, app/Dav/Plugins/CacheInvalidationPlugin.php, app/Dav/Services/CalendarImport.php
  depends: TASK-6
  risk: high — a single read/write tag mismatch turns every read into a miss or leaves stale events, and the DAV plugin is on the CalDAV hot path. It doesn't touch the `pgsql` schema or the DAV routes.

TASK-8: Tag `contacts_v2`:
  - Add `ContactService::contactCacheTags(string $userId): array` (`['user_<id>']`).
  - Switch the `remember` (`:87`) and every `forget` (`:78,131,140,149,160,170`) to the tagged methods.
  - Switch `ContactImport::clearCache` (`:162`) and `CacheInvalidationPlugin::invalidateContacts` (`:267`) the same way. Drop their duplicated `CACHE_KEY_CONTACTS` constants.
  files: app/Api/Contacts/Services/ContactService.php, app/Dav/Services/ContactImport.php, app/Dav/Plugins/CacheInvalidationPlugin.php
  depends: TASK-6
  risk: medium — a missed untagged `forget` leaves stale contacts for the TTL. Grep for `CACHE_KEY_CONTACTS` and `contacts_v2` afterwards; nothing untagged should remain.

TASK-9: Tag the attachment caches in `EventAttachmentService`:
  - Add one private tag builder (`['user_<id>']`).
  - Switch both `remember` calls (`:23,40`) and every `forget` call (`:70,82,102,114,121-122`) to the tagged methods.
  files: app/Api/Calendars/Services/EventAttachmentService.php
  depends: TASK-6
  risk: low — one self-contained service; every invalidation is internal to it.

TASK-10: Make account deletion one per-user tag flush:
  - In `UserService::forgetUserCache`, replace the `USER_CACHE_PREFIX_KEYS` loop (`:331-333`) with one `flushTags(['user_<id>'])`, built through one of the owners' helpers or a shared per-user tag helper on `UserCacheService`. Don't add a new abstraction beyond that.
  - Keep the returned `cache_keys_flushed` count identical, so the `DeleteUser` command output doesn't change.
  - Update the `USER_CACHE_PREFIX_KEYS` docblock.
  files: app/Api/Users/Services/UserService.php
  depends: TASK-7, TASK-8, TASK-9
  risk: medium — the per-user tag name must be identical in all three owners, or deletion misses one family. Decide in this task where the single `user_<id>` builder lives, and use it in TASK-7/8/9 as well.

TASK-11: Remove `forgetByPrefix` and the scaffolding around it:
  - Delete `UserCacheService::forgetByPrefix` and the `RedisStore` import if it's unused.
  - Delete the TASK-1 double and its bindings.
  - Rename spy expectations from `forgetByPrefix` to `flushTags` with the new tag arguments in `DavApiConsistencyTest`, `ExternalCalendarApiTest`, `InvalidateSharedCalendarCacheTest` and `EventOccurrenceBoundsMigrationTest`. Don't change any user-facing assertion.
  - Update `docs/TESTING.md:229-231`, which says invalidation can only be asserted via a spy.
  files: app/Shared/Services/UserCacheService.php, tests/Support/ (helper), app/Api/Calendars/Tests/*.php, app/Dav/Tests/*.php, app/Api/Users/Tests/DeleteUserTest.php, docs/TESTING.md
  depends: TASK-7, TASK-8, TASK-9, TASK-10
  risk: medium — the brief's proof depends on this diff touching only doubles and spy names. A reviewer should diff the TASK-2 to TASK-5 tests between the gate commit and this one.

TASK-12: Schedule `cache:prune-stale-tags` and update the rules:
  - In `routes/console.php`, schedule `cache:prune-stale-tags user_data` hourly under a `Cache` group comment (rule 9.3).
  - Rewrite rule 8.2 in `docs/STANDARDS.md:651-652` to describe the per-user and per-calendar tags and `flushTags` instead of `forgetByPrefix`. The line refs there are stale anyway.
  - Mention tagged keys in rule 8.1.
  files: routes/console.php, docs/STANDARDS.md
  depends: TASK-11
  risk: low — config and docs only. The hourly frequency is a judgement call; the brief doesn't say.

TASK-13: Verify against a real Redis, following brief item 3:
  - Point the `user_data` store at a real Redis.
  - Run an API event write, a CalDAV PUT, and a shared-calendar write followed by the job.
  - Confirm that each next read shows the change and that an unchanged read is a hit.
  - Confirm that `INFO commandstats` shows no `cmdstat_keys` increase. Also check that `EVAL`/`ZSCAN` are allowed.

  Record the commands and output in `implementation.md`.
  files: implementation.md (job dir); possibly an opt-in test that is skipped when no Redis is reachable (like `RemoteCalDavClientLiveTest`)
  depends: TASK-11
  risk: high — the report says the container has no Redis, and installing one needs approval. If it can't be run here, the developer must report `NEEDS-HUMAN-INPUT` rather than mark the job done.

TASK-14: Run `composer test` and `composer analyse` in the worktree; both must exit 0.
  files: none
  depends: TASK-12, TASK-13
  risk: low — the final gate; only fails on real regressions.

## Unclear / for the owner

- **Brief item 3 (real Redis)** may not be doable in the container. See TASK-13.
- **Prune frequency** for `cache:prune-stale-tags`: the brief gives none, so this plan proposes hourly.
- **The `cache_keys_flushed` count** is kept unchanged (TASK-10). Say so if a changed number in the admin CLI output is acceptable.
