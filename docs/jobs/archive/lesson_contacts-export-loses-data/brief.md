# Brief: contacts-export-loses-data

type: feature
id: lesson
branch: feature/lesson_contacts-export-loses-data
date: 2026-11-24
author: Leander Muskalla

# Contacts export loses data

**Status:** open — bug, found 2026-11-24 while researching the contacts import
**Area:** api
**Related:** `contacts-vcf-and-phone-import.md` (round-trip export → import depends on this)

## Problem

The data export's `contacts.vcf` doesn't contain the contacts as they are stored.
Each contact is rebuilt from `ContactDTO`, which only has room for:

- one email, one phone, one address
- name parts, organization, photo, groups, note

Everything else is dropped: extra emails, phones and addresses, the type labels
(home/work/mobile), birthday, URLs, nicknames, job title, custom/client fields
(`X-…`), and so on. This affects contacts that came in via CardDAV clients
(phones via DAVx⁵, Thunderbird, iOS) or the CardDAV account import, because those
store full vCards.

**Effect for users:** a user who exports their data as a backup, or to move to another
service, silently loses contact information. Once the `.vcf` import exists, an
export → re-import round-trip loses data too.

## Cause (as found in the code)

`api/app/Api/Export/Services/ContactExportService.php`:

```php
foreach ($this->davService->addressBooks()->contacts()->list($book) as $contact) {
    $vcards[] = $contact->toVCard();   // rebuilt from ContactDTO, not the stored card
}
```

The calendar export already does this right: `CalendarExportService::readStored()` parses
the stored `EventDTO::$storedData` and only falls back to `toVCal()` if the stored data is
missing or unreadable.

`ContactDTO::fromSabre()` reads `$row['carddata']`, but the DTO doesn't keep the
raw card, so there's no equivalent of `storedData` on contacts yet.

## Fix

- Make the stored vCard available to the export, either:
  - a `storedData` property on `ContactDTO` (set in `fromSabre()`), matching `EventDTO`, or
  - having the export read `carddata` directly from the backend.

  Pick whichever fits the existing patterns; mirroring `EventDTO` is probably the most consistent.
- In `ContactExportService`, export the stored card as it is. Fall back to `toVCard()` only if
  the stored card is missing or doesn't parse, like `readStored()` does for calendars.
- Make sure each card ends with a line break and the cards are joined cleanly. The current
  `implode("\r\n", …)` relies on how `toVCard()` ends its output.
- Watch memory with photos: stored cards contain embedded `PHOTO` data. Check whether
  `list($book)` already loads photos today, and keep the export streaming or chunked
  if needed (the import side uses `gc_collect_cycles()` for the same reason).

## Tests

There's no `ContactExportServiceTest` yet (`app/Api/Export/Tests/` only has the calendar,
check-in, command and general export tests). Add one:

- A card stored via DAV with two emails, two phones, two addresses with types, birthday,
  URL and an `X-` property → all present in the export.
- A contact created through the app's API → exported correctly.
- A card with an embedded photo → photo present.
- An unreadable stored card → falls back to the rebuilt version, export doesn't fail.
- Several address books → all contacts in the file, valid multi-contact `.vcf`
  (parses with `Sabre\VObject\Splitter\VCard`).

## Open question

All address books currently end up in one `contacts.vcf`, so which address book a contact
belonged to is lost. Keep it like that (simple, the common format) or write one file per
address book? Not required for the bug fix; decide separately.
