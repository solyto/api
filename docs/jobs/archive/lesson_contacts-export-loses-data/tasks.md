# Tasks: contacts-export-loses-data

id: lesson
analyst: analyst
date: 2026-11-24

<!-- Produced by @analyst from brief.md. -->

## Findings that shape the plan

- `Contacts::list()` → `AppContactsPDO::getContactsCustom()` calls `ContactDTO::fromSabre($row, $book, false)`,
  so photos are **stripped** in the list path. The current export therefore already drops every `PHOTO`, not only
  the extra fields (answers the brief's "does `list()` load photos" question: no).
- The same `list()` result is cached in Redis by `ContactService::listContacts()` (`rememberTagged`). Setting a
  `storedData` property inside `getContactsCustom()` (the direct `EventDTO` mirror) would put every full card,
  photos included, into that cache and into the contacts list response path. So the stored card must be loaded
  through a separate, export-only read — not by changing `getContactsCustom()`.
- `getContactsCustom()` uses `while ($row = $stmt->fetch())`, so streaming row by row is possible; `carddata` may
  come back as a resource (pgsql bytea) — `fromSabre()` already handles that, the new read must too.
- Raw PDO is only allowed in `app/Dav` (STANDARDS §5); the new read belongs in `AppContactsPDO`. It is a read-only
  `SELECT` on the existing `cards` table — no change to the `pgsql` connection, schema or DAV routes.
- `ContactDTO::toVCard()` ends without a trailing CRLF; the export's `implode("\r\n", …)` leaves the file without
  a final line break.

## Task breakdown

TASK-1: Add `public ?string $storedData = null;` to `ContactDTO` (mirroring `EventDTO`, with a one-line comment
     that only the export read sets it, so listings and caches never carry it).
     files: app/Dav/DTOs/ContactDTO.php
     depends: none
     risk: low — additive property with a null default; no existing caller sets or reads it.

TASK-2: Add an export-only read to `AppContactsPDO` (e.g. `getStoredContactsCustom(AddressBookDTO)`) that selects
     the address book's cards and yields them one at a time (generator) as `ContactDTO`s with `storedData` set to
     the raw `carddata` (resource → string), plus a matching pass-through on `App\Dav\Services\Contacts`
     (e.g. `listStored()`); leave `getContactsCustom()` / `list()` unchanged.
     files: app/Dav/Backend/AppContactsPDO.php, app/Dav/Services/Contacts.php
     depends: TASK-1
     risk: medium — new PDO read on the DAV (`pgsql`) side; read-only and schema-neutral, but per the hard rule it
     must be called out in implementation.md as touching the DAV backend.
     note: the fallback DTO should be built with `includePhoto: true` so a rebuilt card keeps its photo; since the
     generator holds one card at a time this does not raise peak memory meaningfully.

TASK-3: Rewrite `ContactExportService::export()` to iterate every address book via the TASK-2 read, parse each
     `storedData` with `Sabre\VObject\Reader::read()` and write `$vcard->serialize()` when it is a `VCard`, falling
     back to `Reader::read($contact->toVCard())->serialize()` (or `toVCard()` + `"\r\n"`) when the stored card is
     missing or unreadable (catch `\Throwable`, like `CalendarExportService::readStored()`); write card by card to
     an open file handle instead of collecting an array + `implode`, so every card ends in CRLF and memory stays
     bounded with photos.
     files: app/Api/Export/Services/ContactExportService.php
     depends: TASK-2
     risk: medium — changes the exported file format for every user (now full cards, original VERSION kept); must
     still produce a valid file when a user has no contacts (empty file, as today).

TASK-4: Add `app/Api/Export/Tests/ContactExportServiceTest.php` (structure like `CalendarExportServiceTest.php`,
     using `davUser()`, `davRequest()` PUT to the default address book, `davVcard()`) covering the brief's five
     cases: (a) DAV card with 2 EMAIL, 2 TEL, 2 ADR with TYPEs, BDAY, URL and an `X-` property all present;
     (b) contact created through the app (`ContactService`/API) exported correctly; (c) card with embedded PHOTO →
     PHOTO present; (d) unreadable stored card → rebuilt card exported, no exception; (e) two address books →
     all contacts present and the file parses with `Sabre\VObject\Splitter\VCard` into the expected count.
     files: app/Api/Export/Tests/ContactExportServiceTest.php
     depends: TASK-3
     risk: medium — case (d) cannot go through a DAV PUT (Sabre validates the card, and `VCardPhotoProcessor`
     runs on `createCard`); the test likely has to insert the broken `carddata` directly into the `cards` table
     on the `pgsql` connection, which is allowed in Tests but should be kept minimal. Case (c): the PHOTO written
     via DAV is rescaled by `VCardPhotoProcessor`, so assert presence/type, not the exact bytes.

TASK-5: Run `composer test` and `composer analyse` and fix anything the change introduced (e.g. PHPStan generic /
     return types on the generator).
     files: whatever TASK-1..4 touched
     depends: TASK-4
     risk: low — verification step; required for the job to count as done.

## Out of scope / open

- One file per address book vs. a single `contacts.vcf` (brief's open question): not decided here; keep the single
  file. Needs a separate owner decision.
- The contacts list path (`getContactsCustom`, Redis cache) stays as it is.
