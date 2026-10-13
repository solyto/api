# Verdict: full user deletion routine

id: reduction
reviewer: @reviewer
date: 2026-10-13

<!-- Produced by @reviewer and/or @security after implementation. -->

## Review (round 2 — re-review after NEEDS WORK, commit 8b2c092)

Method: full branch diff `main...HEAD` (9 code files + job docs), with focus
on the rework diff `8b2c092..HEAD`. This session has `vendor/`, so the suite
was re-run independently: `php artisan test app/Api/Users app/Dav` and the
full suite (703 tests, 1796 assertions, 0 failures). Every test shows a
warning, including tests this branch doesn't touch — that's the missing
`.env` file in this checkout.

TASK-1: PASS
notes: unchanged since round 1 — `Principals::delete()` removes the principal,
both proxy principals and the groupmembers rows in both directions, using
exact `IN` matches.

TASK-2: PASS
notes: unchanged since round 1. Shared instances are deleted first
(`principaluri = ? AND access > 1`, the same WHERE clause as
`unshareCalendar`), and the `isShared` check is a second guard on the
owned-calendar loop. Both DavServiceTest safety cases pass in this run.

TASK-3: PASS
notes: round-1 blockers fixed in app/Api/Users/Services/UserService.php:
- `password_reset_tokens` rows are now deleted by email inside the
  default-connection transaction, and the count is reported.
- `sessions` rows are deleted by `user_id` in the same transaction.
- The three `*_release_notified` keys moved to `USER_LONGTERM_CACHE_KEYS` and
  are forgotten through `new UserCacheService('longterm')`. I checked this
  against the writers: GrabBook/Movie/MusicReleases.php:31/57 store
  `[CACHE_KEY_NOTIFIED, $user->id]` on the same store with the same key
  shape. They are no longer in the `user_data` list, so there's no
  double-handling. The total key-family count stays at 39.

TASK-4: PASS
notes: the summary line now also reports password reset tokens and sessions.
Nothing else changed.

TASK-5: PASS
notes: DeleteUserTest now seeds and asserts:
- an app-side AddressBook + Contact (the factories' `forUser` /
  `forAddressBook` methods exist)
- a `password_reset_tokens` row via `Password::broker()->createToken`, with
  another user's token asserted to survive
- a `sessions` row
- a `longterm` `book_release_notified` key, with another user's key asserted
  to survive

The `longterm` store maps to `array` in tests (tests/TestCase.php:59-61), so
these assertions really exercise the fix.

TASK-6: PASS
notes: implementation.md now lists `password_reset_tokens` and
`sessions.user_id` in the audit as FK-less stores that are deleted. It also
corrects the cache claim to 36 keys on `user_data` plus 3 on `longterm`.
That matches the code.

Scope: clean. The rework only touches the four files the round-1 blockers
named (UserService, DeleteUser, DeleteUserTest, implementation.md). Nothing
else changed outside tasks.md, and no pgsql config, schema or route was
touched. The working tree has an uncommitted `.env.testing` modification and
tokens.json (manigot state). Neither is part of the branch, and I did not
inspect `.env.testing` (secrets rule).

## Security

All round-1 privacy findings are resolved. The deleted user's email no longer
stays behind in `password_reset_tokens`, and their id no longer stays behind
in the `longterm` cache. The new SQL uses placeholders (query builder
`where`). No secrets appear in the diff or the tests.

## Overall

APPROVED

None of the remaining items block the merge. The out-of-scope follow-ups
(Pulse/Telescope rows, queue jobs that hold a User model, orphaned `feeds`
rows) are recorded in implementation.md.
