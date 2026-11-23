# Implementation: Replace Redis KEYS cache invalidation with cache tags

id: safety
developer: developer
date: 2026-11-23

## Summary

Calendar event, contact and event-attachment caches are now stored under Laravel cache tags instead of being prefix-deleted with Redis `KEYS`. `UserCacheService` gained tagged read/write/forget methods and `flushTags()`. `forgetByPrefix()` is gone. Behaviour tests for every invalidation path were written first and passed on the old code with a prefix-delete double (gate: `composer test` exit 0, 2080 tests at `72b2232`). After the switch they pass unchanged without the double. The real-Redis check (brief item 3, TASK-13) is **not done**: the container has no Redis.

## Changes

TASK-1: `tests/Support/PrefixDeletingUserCache.php` held the prefix-delete double from `CalendarApiTest` so all the new tests could share it. Removed in TASK-11.

TASK-2: `app/Api/Calendars/Tests/CalendarCacheBehaviourTest.php`. Each test reads the month, widget and today listings, writes, then reads again. Covers create, update, delete, move (both calendars), occurrence edit and delete, recolour and calendar delete, plus a cache-hit test (a DAV change made behind the cache stays invisible until a write). With the flush disabled, 7 of 8 fail. Calendar delete can't fail for the owner, because the deleted calendar leaves the cached list and its event cache is never read again.

TASK-3: `CalendarApiTest` covers revoke and unsubscribe, each followed by an owner write, a re-invite and an accept. Each test fails without its specific flush. The owner/sharee propagation test already existed. `InvalidateSharedCalendarCacheTest` gained a behaviour test: the owner and the accepted sharee see the change, an outsider stays cached.

TASK-4: `DavApiConsistencyTest` covers CalDAV PUT create/overwrite and DELETE, a DAV write reaching the other members of a shared calendar, a DAV collection delete dropping the shared calendar from the sharee, and a CardDAV overwrite.

TASK-5:
- External calendars (`ExternalCalendarApiTest`): the 412 and unreadable stale-drop, and `forgetRemoteState` via the password fix.
- Occurrence-bounds migration: owner and sharee see the repaired series. The fixture moved into `boundsFixture()`.
- Attachments: attach and detach for todos and notes, each read before the write.
- Calendar and contact import (`DavImportTest`).
- Account deletion (`DeleteUserTest`): events, contacts, todo and note attachments are no longer served, and another user's caches survive. For the deleted user, the untagged `calendars_v2` and `address_books` lists are re-stored after deletion, so the event and contact caches are read again through the services.

TASK-6: `UserCacheService::storeTagged/getTagged/rememberTagged/forgetTagged/flushTags` and `userTag()` (`user_<id>`), keeping the same semantics: a falsy value is a miss, a non-positive TTL means forever, get/forget log `InvalidArgumentException`. `flushTags` catches `\Throwable`, which includes the `BadMethodCallException` from a store without tag support. Unit tests are in `tests/Unit/UserCacheServiceTest.php`. No batched read was added; `collectEvents` doesn't need one.

TASK-7:
- `CalendarService::eventCacheTags()` returns `[user_<id>, calendar_events_<id>_<cal>]`, and `eventCacheTag()` returns the calendar tag.
- `collectEvents` reads and writes with the tags. All 13 `forgetByPrefix` calls in `CalendarService` now flush the calendar tag, and so do `InvalidateSharedCalendarCache`, `CacheInvalidationPlugin` and `CalendarImport`.
- The duplicated `CACHE_KEY_EVENTS` constants are gone, and `CalendarService::CACHE_KEY_EVENTS` is now private.
- The spy expectations broken by the switch were renamed in this commit instead of in TASK-11, so the suite stayed green. The account-deletion test was red until TASK-10.

TASK-8: `ContactService::contactCacheTags()` and `contactCacheKey()`. The `remember` call, all 6 `forget` calls, `ContactImport::clearCache` and `CacheInvalidationPlugin::invalidateContacts` now use the tagged methods. The duplicated `CACHE_KEY_CONTACTS` constants are gone.

TASK-9: `EventAttachmentService` builds its tags in a private `cacheTags()` method; both `remember` calls and all forgets are tagged.

TASK-10: `UserService::forgetUserCache` replaces the prefix loop with one `flushTags([UserCacheService::userTag($id)])`. The `cache_keys_flushed` count is unchanged.

TASK-11: removed `forgetByPrefix`, the `RedisStore` import, the double and its bindings, plus the outdated comment in `ExternalCalendarApiTest`. Updated `docs/TESTING.md`. `git diff 72b2232 -- <test dirs>` shows only double/import lines and spy names.

TASK-12: `routes/console.php` schedules `cache:prune-stale-tags user_data` hourly; `schedule:list` shows it. Rule 8.1 now describes tagged entries and rule 8.2 describes `flushTags` (`docs/STANDARDS.md`).

TASK-13: **not done.** The container has no Redis server, no phpredis extension and no predis. `php artisan schedule:list` fails with `Class "Redis" not found` unless the cache store is overridden. Brief item 3 still has to be run against a real Redis:
1. Point `user_data` at a real Redis.
2. Do an API event write, a CalDAV PUT, and a shared-calendar write followed by `InvalidateSharedCalendarCache`. Check that each next read shows the change and that an unchanged read is a hit.
3. Compare `INFO commandstats` before and after: `cmdstat_keys` must not increase.
4. Confirm `EVAL`/`ZSCAN` are allowed.

TASK-14: `composer test` exited 0 (2087 tests) and `composer analyse` exited 0 ("No errors").

## Known issues / follow-ups

- Brief item 3 (real Redis verification) is outstanding, see TASK-13. The job is not done until it is.
- Environment: the worktree had no `vendor/`, `storage/framework/*` or `.env`, so I ran `composer install` from the lock file and created an empty, git-ignored `.env` and the storage directories. `.env.testing` is mounted as a character device and shows as modified in `git status`; it can't be committed and wasn't touched.
- Behaviour test coverage is partial in a few places. Calendar delete (owner, API and DAV) and the failed-move cleanup in `ExternalCalendarApiTest` don't change what a read returns, so those flushes are still asserted only by spies or not at all.
