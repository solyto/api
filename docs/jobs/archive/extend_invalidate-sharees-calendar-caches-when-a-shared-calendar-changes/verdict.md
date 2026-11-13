# Verdict: invalidate sharees' calendar caches when a shared calendar changes

id: extend
reviewer: reviewer
date: 2026-11-13

## Review

Branch `feature/extend_invalidate-sharees-calendar-caches-when-a-shared-calendar-changes`,
diff `main...HEAD`. I ran both `composer test` (1415 passed, exit 0) and
`composer analyse` (no errors, exit 0) in this worktree myself.

TASK-1: PASS
notes: The migration matches the brief. It works on both pgsql and SQLite
(`FALSE`/`0` default), creates the `calendarinstances_calendarid` index, backfills
only `access = 1` rows that have an accepted sharee, and `down()` drops the index
before the column. The migration has a comment explaining it adds our column to
Sabre's table. It's on the allowlist with a reason. The full migration is in
implementation.md for the owner to see before deploy.

TASK-2: PASS
notes: `recomputeSharedFlag()` is the brief's idempotent EXISTS update, scoped to
the owner row. It's called from `acceptShare()` (now a scoped SELECT, then an
UPDATE by id, returning `?int`), `unshareCalendar()`, `deleteCalendarCustom()`
when `isShared`, and `deleteSharedInstancesCustom()` (ids collected before the
delete). `CalendarSharing`/`CalendarService` were adjusted for the `?int` return.

TASK-3: PASS
notes: `CalendarDTO::$solytoShared` is filled with explicit bool casts in
get-by-id and get-by-name. `listCalendarsCustom()` uses one extra query and a
strict `in_array` against int-cast ids (Sabre's `id[1]` is an int).
`CalendarResource` doesn't expose the field.

TASK-4: PASS
notes: `getCalendarMemberIds()` / `getCalendarOwnerId()` are in `AppCalendarsPDO`
and exposed through `CalendarSharing`. No new `connection('pgsql')` under
`app/Api` or `app/Shared`; the only test hit there was already on main.

TASK-5: PASS
notes: The job follows the spec (unique until processing, per calendar, 60 s, and
it doesn't exclude the editor). It uses `CalendarService::CACHE_KEY_EVENTS`,
which was made public. That change is noted in implementation.md and is there so
the key prefix can't drift.

TASK-6: PASS
notes: The owner's calendar list is forgotten on accept (owner resolved from the
returned calendarId), on revoke (`$request->user()`, which the controller has
already checked is the owner), and on unsubscribe (owner looked up before the
delete).

TASK-7: PASS
notes: One private helper, called only on success next to the existing forgets,
and for both `$from` and `$to` in `moveEvent`. No delay.

TASK-8: PASS
notes: The existing lookup now uses `->first([...])` with the right columns. The
dispatch has the 10 s delay and stays inside the existing try/catch. DAV suites
pass unchanged.

TASK-9: PASS
notes: `SharedCalendarFlagTest` covers every lifecycle case, the DTO field and
the backfill (via down/up on seeded rows).

TASK-10: PASS
notes: Covers the job's member scope, re-warming and per-calendar uniqueness;
API dispatch for owner and sharee, for no share, and shared→unshared move pushing
once; DAV PUT/DELETE × owner/sharee plus the unshared case.

TASK-11: PASS
notes: The end-to-end tests run in both directions and check the owner's list
flag through accept, revoke and unsubscribe. The `UserCacheService` double is
only bound in that describe block and is needed because `forgetByPrefix` does
nothing on the array store. No DAV test files were changed.

Scope: nothing changed outside tasks.md. The open question (owner deletes a
shared calendar) is correctly left out and recorded as a follow-up.

## Security

Not run. No new endpoints. `acceptShare` stays scoped to the caller's own pending
invite.

## Overall

APPROVED

No blockers.
