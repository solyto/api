# Verdict: sharees keep seeing a shared calendar after the owner deletes it

id: run
reviewer: reviewer
date: 2026-11-14

## Review

Reviewed `git diff main...HEAD` on `feature/run_sharees-keep-seeing-a-shared-calendar-after-the-owner-deletes-it`. Only the four planned source files changed, plus the job docs. No unexpected changes.

TASK-1: PASS
notes: `CalendarService::destroy()` collects `user_id` from `sharing()->listSharees()` before `delete()`. After the delete it clears the list and event caches for the owner and each sharee. The developer changed the guard from `solytoShared` to `! isShared && ! isExternal`, and explained why in implementation.md. That is correct: `solyto_shared` is only set after an invite is accepted, and pending sharees still see the calendar in their list. `getCalendarSharees()` only returns `access > 1` rows, so the owner isn't counted twice. Declined sharees are also cleared, which does no harm. External calendars and a sharee's own delete keep the old behaviour.

TASK-2: PASS
notes: `CalendarApiTest` covers accepted and pending sharees (list and month events) and checks that deleting an unshared calendar doesn't clear anyone else's cache.

TASK-3: PASS
notes: `beforeUnbind` is registered at the default priority (100), so it runs after the ACL check at priority 20. A rejected DELETE never reaches it, and the 403 test confirms this. The ids are stored per path, reset on every collect, and used up in `onAfterUnbind`, so a MOVE or a failed delete can't leave state that a later request would pick up. The plugin is created per request. It only reads `pgsql`: the schema, connection and DAV routes are unchanged. User ids are strings, which matches `RefreshCalendarCache(string $userId)` and the `@var` annotation. The existing try/catch and `dav` log style is kept.

TASK-4: PASS
notes: `DavApiConsistencyTest` covers:
- an end-to-end check with the real cache, in the "DAV and API agree" block
- a spy test that checks the forgets and queued refreshes for each sharee
- a sharee deleting their own instance, which doesn't touch the owner or the other sharee
- an ACL-rejected DELETE (403), which clears nothing and queues nothing

## Verification

- `composer test`: exit 0, 1533 tests, 5482 assertions, no failures. Every test shows a warning because `/workspace/.env` is missing from the worktree (phpdotenv `file_get_contents` warning). That comes from the environment, not this change.
- `composer analyse`: `[OK] No errors`.

## Security

none: no new input surface. The DAV hook runs only after the ACL check passes.

## Overall

APPROVED. Nothing blocks the merge.
