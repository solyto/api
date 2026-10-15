# Tasks: Calendar and contact controller not-found preamble

id: insurance
analyst: @analyst
date: 2026-10-15

<!-- Produced by @analyst from brief.md. -->

Branch checked: the worktree HEAD is `feature/insurance_calendar-and-contact-controller-not-found-preamble`, which matches `brief.md`.

## DAV flag (required by brief and AGENTS.md hard rules)

`CalendarService::get()` and `ContactService::getAddressBook()`/`getContact()`
read through `$this->dav->…` (SabreDAV backends on the `pgsql` connection).
This job **only moves the null check** into the service layer. It changes **no**
DAV backend, no `pgsql` query or schema, and no DAV route (`routes/web.php`).
Nothing under `app/Dav/` is touched. If a task seems to need a change there,
stop and flag it.

## Decision: shape

**Use a typed not-found exception thrown by the service (rule 7.3), not a private `…Or404()` helper (rule 4.4).**

Why:
1. A private controller helper cannot end the action early unless it throws.
   It would have to throw something anyway, such as
   `HttpResponseException(ApiResponse::error(...))`. In `bootstrap/app.php`
   the catch-all `Throwable` render callback runs before Laravel's own
   `HttpResponseException` handling. That would most likely turn the 404 into
   a 500 "An unexpected error occurred.". (I could not verify this against
   `vendor/`, which is not present in this worktree.) So the helper route has
   no clean form here.
2. The pattern already exists in this module pair. `CalendarAlreadyExistsException`
   and `AddressBookAlreadyExistsException` are thrown by the services and mapped
   centrally. The code-quality audit (§3.5, §5.4 item 5) recommends this route.
3. **No new `if` in `bootstrap/app.php`.** The new exceptions extend
   `Symfony\Component\HttpKernel\Exception\HttpException` with status 404 and
   the existing message as the exception message. They then fall into the existing
   `HttpException` branch (`bootstrap/app.php:87-92`), which renders
   `ApiResponse::error($e->getMessage(), $e->getStatusCode())`. That is
   byte-identical to today's `ApiResponse::error('Calendar does not exist', 404)`.
   They must **not** extend `NotFoundHttpException`, because that branch (`:71-73`)
   replaces the message with "The requested resource was not found.".
   `HttpException` is also on Laravel's internal don't-report list, so nothing
   new gets logged, which matches today's behaviour. This leaves the
   `AlreadyExists` mapping (§3.6) untouched, as the brief requires.
4. The nullable lookups stay. `CalendarService::get()` is still needed nullable
   for the "Target calendar does not exist" lookup (`CalendarController.php:391`).
   `ContactService::getAddressBook()`/`getContact()` are still needed nullable
   inside `ContactService::getContactPhotos` (`ContactService.php:96,100`, which
   skips missing entries). So the throwing lookups are **new** service methods
   next to the existing ones, not a change in what `get()` returns.

If the owner prefers the helper route instead, only TASK-2 to TASK-5 change.
TASK-1 and TASK-6 hold either way.

## Task breakdown

TASK-1: Pin today's 404 responses in tests before refactoring. Extend the
existing tenant-isolation tests so each 404 also asserts the exact `message`
("Calendar does not exist", "Address Book does not exist", "Contact does not exist").
Add 404 cases for the calendar actions not covered yet: `updateEventOccurrence`,
`destroyEventOccurrence`, `revokeShare`, `unsubscribeFromCalendar`.
     files: app/Api/Calendars/Tests/CalendarApiTest.php, app/Api/Contacts/Tests/ContactApiTest.php
     depends: none
     risk: low. Test-only, and it must pass against the unchanged controllers first; that proves the baseline.

TASK-2: Add `App\Api\Calendars\Exceptions\CalendarNotFoundException`, which extends
`Symfony\…\HttpException`, has status 404 and message `'Calendar does not exist'`
(exactly, no trailing period), and has no `//` stub body. Add
`CalendarService::getOrFail(User $user, int $instanceId): CalendarDTO`, which
calls `get()` and throws on `null`. Give it a `@throws` docblock like `create()` has.
     files: app/Api/Calendars/Exceptions/CalendarNotFoundException.php (new), app/Api/Calendars/Services/CalendarService.php
     depends: none
     risk: low. Additive; `get()` keeps its nullable signature for the target-calendar lookup.

TASK-3: In `CalendarController`, replace the eleven
`get()` + `if (null) return ApiResponse::error('Calendar does not exist', 404)`
blocks with `$calendar = $this->calendarService->getOrFail($request->user(), $instanceId);`.
The actions are `updateCalendarColor`, `destroyCalendar`, `storeEvent`,
`updateEvent`, `destroyEvent`, `destroyEventOccurrence`, `updateEventOccurrence`,
`listSharees`, `shareCalendar`, `revokeShare` and `unsubscribeFromCalendar`.
Leave the "Target calendar does not exist", "Event does not exist" and
"User does not exist" checks unchanged.
     files: app/Api/Calendars/Controllers/CalendarController.php
     depends: TASK-1, TASK-2
     risk: medium. Eleven near-identical edits in a ~1 000-line file; the target-calendar lookup at :391 must stay on nullable `get()`.

TASK-4: Add `App\Api\Contacts\Exceptions\AddressBookNotFoundException`
(404, `'Address Book does not exist'`) and `ContactNotFoundException`
(404, `'Contact does not exist'`), both shaped like TASK-2. Add
`ContactService::getAddressBookOrFail(User, int): AddressBookDTO` and
`ContactService::getContactOrFail(AddressBookDTO, string): ContactDTO`.
     files: app/Api/Contacts/Exceptions/AddressBookNotFoundException.php (new), app/Api/Contacts/Exceptions/ContactNotFoundException.php (new), app/Api/Contacts/Services/ContactService.php
     depends: none
     risk: low. Additive; `getContactPhotos` keeps using the nullable lookups.

TASK-5: In `ContactController`, replace the seven address-book blocks and the four
contact blocks with the `…OrFail()` calls. The address-book actions are
`updateAddressBook`, `destroyAddressBook`, `storeContact`, `updateContact`,
`destroyContact`, `updateContactPhoto` and `removeContactPhoto`. The contact
actions are the last four of those. Leave the post-write `if ($contact === null)`
checks (`:431`, `:574`, `:637`, the "Error updating contact" 500s) unchanged; they are out of scope.
     files: app/Api/Contacts/Controllers/ContactController.php
     depends: TASK-1, TASK-4
     risk: medium. Each of the four contact actions has a lookup-404 and a post-write null check close together; it is easy to replace the wrong one.

TASK-6: Run `composer test` and `composer analyse` in the worktree. Both must
exit 0. If removing the null checks leaves `phpstan-baseline.neon` entries for
these lines unmatched, remove only those entries.
     files: phpstan-baseline.neon (only if needed)
     depends: TASK-3, TASK-5
     risk: low. Verification; the baseline edit, if any, is mechanical.

## Unclear / open (not guessed, not in scope above)

- **Event lookups.** `CalendarController` also repeats
  `getEvent()` + "Event does not exist" 404 four times (`updateEvent`, `destroyEvent`,
  `destroyEventOccurrence`, `updateEventOccurrence`). The brief names only the
  calendar, address-book and contact lookups, so the event lookups are left
  alone. Applying the same shape to them would be a natural follow-up, but it needs owner approval.
- **Message punctuation.** Rule 7.4 wants messages to end in a period, but
  the brief requires the messages to stay identical. They stay without a
  period here; changing them is a separate decision.
- **Method naming.** `getOrFail` / `getAddressBookOrFail` / `getContactOrFail` is a
  proposal. No `*OrFail` service method exists in `app/` yet. The developer may
  pick another name if the reviewer prefers, but it should be the same pattern in both services.
