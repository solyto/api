# Verdict: Calendar and contact controller not-found preamble

id: insurance
reviewer: @reviewer
date: 2026-10-15

## Review

Reviewed `git diff main...HEAD` on `feature/insurance_calendar-and-contact-controller-not-found-preamble`.
I ran `composer test` (exit 0, 893 tests, 3324 assertions; the warnings come from the sandbox's
missing `.env`) and `composer analyse` (exit 0, `[OK] No errors`).

DAV flag: nothing under `app/Dav/`, `routes/web.php`, the `pgsql` connection or its schema changed.
The services still read through `DavService`. Only the null check moved.

TASK-1: PASS
notes: The calendar and address-book tenant-isolation 404s now assert the exact `message`. New
404 cases cover `revokeShare`, `unsubscribeFromCalendar`, `updateEventOccurrence` and
`destroyEventOccurrence`. A new contact test covers "Contact does not exist" on update, delete,
photo add and photo remove, which was not tested before.

TASK-2: PASS
notes: `CalendarNotFoundException` extends `HttpException` (not `NotFoundHttpException`) and is
built with `(404, 'Calendar does not exist')`. `getOrFail()` has a `@throws` docblock, and the
nullable `get()` is unchanged.

TASK-3: PASS
notes: There are exactly 11 `getOrFail` call sites, one for each action listed in tasks.md. The
"Target calendar", "Event" and "User does not exist" checks are unchanged
(`CalendarController.php:369,378,450,519,613,739,800`). The controllers have no `try/catch` that
could swallow the new exception.

TASK-4: PASS
notes: `AddressBookNotFoundException` and `ContactNotFoundException` are built the same way as
TASK-2. `getAddressBookOrFail()` and `getContactOrFail()` were added. `getContactPhotos` still
uses the nullable lookups.

TASK-5: PASS
notes: There are 7 address-book calls and 4 contact calls, and they replace the right blocks. The
post-write "Error updating contact" / "Error deleting contact" checks are unchanged.

TASK-6: PASS
notes: Both commands exit 0, as I checked myself. No baseline change was needed or made.

Response equivalence: in `bootstrap/app.php` the new exceptions skip the `NotFoundHttpException`
branch and reach the `HttpException` branch, which renders
`ApiResponse::error($e->getMessage(), $e->getStatusCode())`. That is the same as the old inline
`ApiResponse::error('…', 404)`. `bootstrap/app.php` itself is unchanged.

Scope: the only changes outside `app/` are the job docs and `tokens.json`. Nothing was
refactored outside the scope.

## Security

none (not run). The tenant-isolation behaviour is the same, and the strengthened tests cover it.

## Overall

APPROVED. No blockers.
