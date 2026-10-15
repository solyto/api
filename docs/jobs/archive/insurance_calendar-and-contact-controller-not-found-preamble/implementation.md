# Implementation: Calendar and contact controller not-found preamble

id: insurance
developer: @developer
date: 2026-10-15

## Summary

The repeated lookup-or-404 blocks in `CalendarController` and `ContactController` now use the
typed-exception route (rule 7.3), as `tasks.md` decided. The services gained throwing lookups
(`getOrFail`, `getAddressBookOrFail`, `getContactOrFail`). They throw new not-found exceptions
that extend Symfony's `HttpException` with status 404 and the old message. The existing
`HttpException` branch in `bootstrap/app.php` renders them as
`ApiResponse::error($message, 404)`, so the response is the same as before. `bootstrap/app.php`
was not changed.

**DAV flag:** the services still read calendars, address books and contacts through
`DavService` (the `pgsql` connection). This job only moved the null check from the controllers
into the services. Nothing under `app/Dav/`, no `pgsql` query or schema, and no DAV route was
touched.

## Changes

TASK-1: Pinned the current 404 responses before refactoring.
- `app/Api/Calendars/Tests/CalendarApiTest.php`: the tenant-isolation 404 cases now also assert
  the exact message `Calendar does not exist`.
- Added 404 cases for `revokeShare`, `unsubscribeFromCalendar`, `updateEventOccurrence` and
  `destroyEventOccurrence`.
- `app/Api/Contacts/Tests/ContactApiTest.php`: the 404 cases now assert
  `Address Book does not exist`.
- Added a test for a missing contact in the user's own address book, which asserts
  `Contact does not exist` on update, delete, photo add and photo remove. No existing test
  covered this message.
- These tests passed against the unchanged controllers before any refactoring.

TASK-2: Added `app/Api/Calendars/Exceptions/CalendarNotFoundException.php`, which extends
`HttpException` with status 404 and the message `Calendar does not exist`. Added
`CalendarService::getOrFail()` with a `@throws` docblock. The nullable `get()` is unchanged and
is still used for the "Target calendar does not exist" lookup.

TASK-3: `app/Api/Calendars/Controllers/CalendarController.php`: replaced the eleven
calendar lookup-or-404 blocks with `getOrFail()`. The checks for "Target calendar",
"Event does not exist" and "User does not exist" are unchanged.

TASK-4: Added `app/Api/Contacts/Exceptions/AddressBookNotFoundException.php` and
`ContactNotFoundException.php`, built the same way as in TASK-2. Added
`ContactService::getAddressBookOrFail()` and `getContactOrFail()`. `getContactPhotos` still uses
the nullable lookups.

TASK-5: `app/Api/Contacts/Controllers/ContactController.php`: replaced the seven address-book
blocks and the four contact blocks with the `…OrFail()` calls. The null checks after writes
that return "Error updating contact" (500) are unchanged.

TASK-6:
- `composer test` exits 0 (893 tests). The "warnings" all come from the sandbox having no
  `.env` file (`file_get_contents(/workspace/.env)`).
- `composer analyse` reports `[OK] No errors`.
- No `phpstan-baseline.neon` change was needed.

## Known issues / follow-ups

- **Event lookups (not in scope):** `CalendarController` still repeats the `getEvent()` +
  "Event does not exist" 404 four times. The same shape would work there, but it needs owner
  approval.
- **Message punctuation:** the messages keep their missing final period, because the brief
  requires identical responses. Rule 7.4 asks for a period, which is a separate decision.
- **Flaky test (not related to this job):** the very first `composer test` run in this fresh
  worktree had 2 failures in `app/Api/Export/Tests/ExportTest.php`. The cause was `fopen`
  failing in `FinanceWealthExportService.php:19`. The next three full runs and an isolated run
  all passed. This is probably a storage directory that did not exist yet on the first run.
- **Environment setup:** `vendor/` and `storage/framework/*` were missing in this worktree. I ran
  `composer install` (from the existing lock file, no new packages) and created the gitignored
  storage directories so the suite could run.
