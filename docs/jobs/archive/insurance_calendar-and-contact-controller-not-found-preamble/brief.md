# Brief: Calendar and contact controller not-found preamble

type: feature
id: insurance
branch: feature/insurance_calendar-and-contact-controller-not-found-preamble
date: 2026-10-15
author: Leander Muskalla

## What
`Calendars/Controllers/CalendarController.php` opens eleven actions with the same lookup-or-404 block (`$calendar = $this->calendarService->get(...); if ($calendar === null) { return ApiResponse::error('Calendar does not exist', 404); }`); `Contacts/Controllers/ContactController.php` repeats it seven times for the address book and four more for the contact. Decide the shape first, then apply it to both controllers: a typed not-found exception thrown by the service and mapped centrally in `bootstrap/app.php` (rule 7.3), or a private `calendarOr404()` / `addressBookOr404()` in each controller (rule 4.4).

## Why
Calendars and address books are DAV DTOs, not Eloquent models, so route binding cannot resolve them; every action re-implements the lookup. It is most of why `CalendarController` is ~1 000 lines (code-quality report §3.5).

## Out of scope
The DAV backends, the `pgsql` connection and the DAV routes. The `Error updating contact` 500s in `ContactController`. The `AlreadyExists` mapping in `bootstrap/app.php` (§3.6), unless the exception route is chosen and touches it anyway.

## Notes
DAV-adjacent: the services read the `pgsql` connection; this change only moves the null check, but say so in the job. Response bodies and status codes must stay identical (404 with the same messages); the existing Calendar/Contact API tests assert them.
