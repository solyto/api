# Verdict: Replace Redis KEYS cache invalidation with cache tags

id: safety
reviewer: reviewer
date: 2026-11-23

## Review

Verified independently:
- `composer test` at HEAD: exit 0, 2087 passed. `composer analyse`: exit 0, no errors.
- Gate (brief item 1): extracted `72b2232` (pre-change implementation, prefix-delete double) into a temp dir with its own autoload; the calendar, DAV, user and `UserCacheService` tests ran 717/717 green against the old `forgetByPrefix` code.
- Brief item 2: `git diff 72b2232 HEAD` over all test dirs shows only removal of the double and its bindings, spy method renames (`forgetByPrefix` → `flushTags`, `forget` → `forgetTagged`), one stale spy-explaining comment, and the new `UserCacheServiceTest` cases. No user-facing assertion changed.
- Mutation check: with `flushTags` made a no-op at HEAD, 20 behaviour tests go red (API writes, sharing, sharee job, DAV writes, import, external calendars, bounds migration, account deletion), so the tests check behaviour, not just wiring.

TASK-1: PASS
notes: The shared double (`tests/Support/PrefixDeletingUserCache.php`) was added and then removed in TASK-11, as planned.

TASK-2: PASS
notes: `CalendarCacheBehaviourTest` covers create, update, delete, move, occurrence edit/delete, recolour, calendar delete and a cache-hit test. Owner-side calendar delete can't fail by behaviour; the implementation notes say so.

TASK-3: PASS
notes: Revoke and unsubscribe behaviour tests are in `CalendarApiTest`. `InvalidateSharedCalendarCacheTest` checks members and an outsider by behaviour.

TASK-4: PASS
notes: `DavApiConsistencyTest` covers CalDAV PUT create/overwrite/DELETE, propagation to sharees, collection delete and a CardDAV overwrite.

TASK-5: PASS
notes: External calendar stale-drop and forgetRemoteState, the bounds migration, attachments, calendar/contact import and account deletion (including another user's caches surviving) are all covered.

TASK-6: PASS
notes: `UserCacheService.php` keeps the old semantics: a falsy value is a miss, a non-positive TTL means forever, get/forget log `InvalidArgumentException`. `flushTags` catches `\Throwable` and has a why-comment (rule 4.8). `storeTagged` doesn't catch, matching `store()`.

TASK-7: PASS
notes: Reads and writes in `collectEvents` both use `eventCacheTags()` (one builder, fixed order). All 13 `CalendarService` sites plus the job, plugin and `CalendarImport` flush `eventCacheTag($userId, $calendarId)`, which is the same per-user, per-calendar scope as the old prefix. The duplicated constants are gone.

TASK-8: PASS
notes: Every `contacts_v2` remember/forget (service, `ContactImport`, plugin) goes through `contactCacheTags`/`contactCacheKey`. No untagged `contacts_v2` access is left.

TASK-9: PASS

TASK-10: PASS
notes: One `flushTags([userTag])` call. The `cache_keys_flushed` count is unchanged. The single `user_<id>` builder is `UserCacheService::userTag`, used by all three owners.

TASK-11: PASS
notes: `forgetByPrefix`, the `RedisStore` import and the double are removed. `docs/TESTING.md` is updated.

TASK-12: PASS
notes: `routes/console.php` schedules `cache:prune-stale-tags user_data` hourly under a group comment (rule 9.3). Rules 8.1 and 8.2 are rewritten.

TASK-13: NOT RUN (owner decision)
notes: The brief's owner decision says not to run the real-Redis check in this session; the owner will run it. The steps are recorded in `implementation.md`, so this is not a blocker for this verdict. I read Laravel 12's `RedisTaggedCache::flush`: it uses `EVAL` plus `ZSCAN` of the flushed tag's entry set and no `KEYS`, which matches the intent.

TASK-14: PASS
notes: Both commands exit 0, re-run by the reviewer.

No scope creep found. The only extra change is making `CalendarService::CACHE_KEY_EVENTS` private, a consequence of dropping the duplicates. The changed code complies with STANDARDS rules 4.4, 4.8, 8.1, 8.2, 9.3, 10.1 and 11.1.

## Security

none (not run)

## Overall

APPROVED

Nothing blocks the merge. Brief item 3 (real Redis: invalidation for an API write, a CalDAV write and a sharee, and no `cmdstat_keys` increase) is left to the owner, as the brief's owner decision says.
