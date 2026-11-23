# Brief: Replace Redis KEYS cache invalidation with cache tags

type: feature
id: safety
branch: feature/safety_replace-redis-keys-cache-invalidation-with-cache-tags
date: 2026-11-23
author: Leander Muskalla

## What

Replace `UserCacheService::forgetByPrefix`, which finds keys with Redis `KEYS` (a full scan of cache DB 1), with Laravel cache tags. `UserCacheService` stays the single entry point for per-user caching (rule 8.1). It gets the tag-aware methods the callers need, and `forgetByPrefix` is removed once nothing uses it.

- `UserCacheService`: tagged read, write and remember methods (incl. a batched read if `collectEvents` needs one) and `flushTags(array $tags)`. Same semantics as today: a falsy value counts as a miss, non-positive TTL means forever, failures are logged.
- Calendar event caches are tagged per user and per calendar. Every current `forgetByPrefix` call site flushes the matching tag instead: `CalendarService`, `InvalidateSharedCalendarCache`, `CacheInvalidationPlugin`, `CalendarImport`.
- The other prefix keys (`contacts_v2`, `event_attachment_notes`, `event_attachment_todos`) move to tags too. Account deletion (`UserService::forgetUserCache`) clears all of them with a per-user tag flush.
- Schedule `cache:prune-stale-tags` in `routes/console.php`.
- Update rule 8.2 in `docs/STANDARDS.md` (it names `forgetByPrefix`).

## Why

Every calendar write, including every CalDAV PUT/DELETE, runs a `KEYS` scan whose cost grows with all users' cached keys and blocks Redis for everyone while it runs. On a Redis that disables `KEYS`, invalidation fails silently and users see stale events for up to 7 days.

## Behaviour must not change

This is a pure internals change. **Nothing a user sees may change**: the same API and CalDAV responses, the same freshness after every write, the same TTLs, and cache hits still served from cache. The job must prove that with tests before and after:

1. **Before touching the implementation**, add behaviour-level tests for every invalidation path, and they must pass on the current code: write, then read through the API, and the read shows the change. That covers event create/update/move/delete and occurrence edits, share/revoke/unsubscribe, sharee propagation, CalDAV PUT/DELETE, calendar import, external calendars, and account deletion clearing every per-user key. Also assert that an unchanged read is still a cache hit. Most existing tests only assert that a `forgetByPrefix` spy was called, which checks the wiring, not the behaviour. Because `forgetByPrefix` is a no-op on the array test store, use the prefix-delete double from `CalendarApiTest.php:663` for the before state.
2. **After the change**, the same tests pass with their user-facing assertions unchanged. Only the test double and spy method names may change. The array store supports tags, so the double goes away.
3. **Against a real Redis** (the array store's tag implementation differs from `RedisTaggedCache`), verify manually or in an integration run: invalidation works for an API write, a CalDAV write and a sharee, and `INFO commandstats` shows no `cmdstat_keys` increase.
4. `composer test` and `composer analyse` both exit 0.

If any of these can't be shown, the job is not done.

## Out of scope

- The existing race where a read stores stale data after a concurrent flush. It's unrelated to `KEYS` and is a separate change.
- Changing TTLs or what gets cached.
- `SCAN` as a stopgap, or a generation-key scheme. The owner chose tags (2026-11-23).
- Caches that aren't prefix-deleted today.

## Notes

- Background and call sites: `docs/reports/calendar-cache-invalidation-uses-redis-keys-2026-11-22.md`.
- Reads must use exactly the same tags, in the same order, as writes, or they miss the cache. Build the tag list in one place on the owning service.
- No data migration: tagged keys get different internal names, so the old untagged keys are never read again and expire.
- `cache:prune-stale-tags` does `SCAN` the keyspace, but only when scheduled, not per write. Flushing needs `EVAL`.
- `contacts_v2` and the attachment keys are only prefix-deleted at account deletion. Check how they're invalidated otherwise before tagging them.

## Owner decisions
Do not run check against a real redis in this session. The owner will do it. Test what you can test.
