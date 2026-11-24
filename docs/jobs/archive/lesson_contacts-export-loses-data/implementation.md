# Implementation: contacts-export-loses-data

id: lesson
developer: developer
date: 2026-11-24

## Summary

The data export's `contacts.vcf` now writes every contact's vCard as it is stored (all emails, phones and
addresses with their types, birthday, URLs, `X-` properties, photo). It only falls back to the card rebuilt
from `ContactDTO` when the stored card is missing or doesn't parse, the same way `CalendarExportService::readStored()`
works. Cards are written to the file one at a time and each ends in CRLF.

## Changes

TASK-1: `app/Dav/DTOs/ContactDTO.php`: added `public ?string $storedData = null`, mirroring `EventDTO`. Only the
export read sets it.

TASK-2: `app/Dav/Backend/AppContactsPDO.php`: new `getStoredContactsCustom()`, a read-only generator over the address
book's `cards` rows. It yields one `ContactDTO` at a time, photo included, with `storedData` set to the raw
`carddata` (a pgsql resource is read into a string). `app/Dav/Services/Contacts.php`: added the `listStored()`
pass-through. `getContactsCustom()`/`list()` and the Redis-cached contacts list are unchanged, so full cards never
end up in the list cache.
**DAV flag:** this adds a method to the SabreDAV contacts backend. It is a plain `SELECT id, uri, carddata` on the
existing `cards` table. It does not change the `pgsql` connection, the schema or the DAV routes.

TASK-3: `app/Api/Export/Services/ContactExportService.php`: goes through every address book with `listStored()`.
A stored card that parses as a `VCard` is written with `serialize()`. Otherwise `toVCard()."\r\n"` is written
(`\Throwable` is caught, like in the calendar export). Each card goes straight to an open file handle instead of
an array plus `implode`. A user with no contacts still gets an empty file.

TASK-4: `app/Api/Export/Tests/ContactExportServiceTest.php`: covers the brief's five cases plus the empty file:
- A DAV card with 2 EMAIL, 2 TEL and 2 ADR (all with TYPE), BDAY, URL and `X-CUSTOM-FIELD`.
- A contact created through `ContactService`.
- An embedded PHOTO, which keeps its exact bytes.
- An unreadable stored card. It is inserted directly into `cards` on `pgsql` because a DAV PUT would reject it. The
  test checks that it comes back as the rebuilt card and the export doesn't fail.
- Two address books. The file parses with `Sabre\VObject\Splitter\VCard` and ends with `END:VCARD\r\n`.

TASK-5: `composer test` and `composer analyse` both exit 0. No fixes were needed.

## Known issues / follow-ups

- The export file format changes for every user: cards now keep their original `VERSION` (3.0 or 4.0, whatever
  the client stored), so one `contacts.vcf` can mix both versions. Sabre's splitter and common importers handle
  this, but it is a visible change.
- Memory: the export now holds only one card at a time in PHP. However, `pdo_pgsql` buffers the whole result set
  of a single address book on the client. If one address book has a lot of photos, a server-side cursor or a
  chunked query could follow later if that turns out to matter.
- Sabre's reader is forgiving: a card that only lacks `END:VCARD` still parses and is exported from the stored
  data, not rebuilt. Only cards that can't be parsed at all fall back.
- The open question (one file per address book) is still undecided. The export keeps a single `contacts.vcf`.
- Running the tests needed `composer install` plus `storage/framework/*` and `bootstrap/cache` directories, which
  were missing from the worktree. Both are gitignored. Composer warns that `composer.lock` is out of date with
  `composer.json`. This existed before the job and is not part of it.
