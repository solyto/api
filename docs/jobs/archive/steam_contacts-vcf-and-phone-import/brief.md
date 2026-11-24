# Brief: contacts-vcf-and-phone-import

type: feature
id: steam
branch: feature/steam_contacts-vcf-and-phone-import
date: 2026-11-24
author: Leander Muskalla

## What

Users can upload a `.vcf` file and import its contacts into a new address book or one of their own (backlog `contacts-vcf-and-phone-import`). API side: `POST /api/v1/address-books/import/file` (multipart: `file`, and `address_book_id` or `name`), a queued import, and progress through `GET /address-books/import/state`. The existing CardDAV account import is hardened in the same job. Contract: room decision `01M8775WWFJV62MYGKTF61CYGM`.

## Why

Contacts can only come in through a CardDAV account today. Exports from Google, Apple, Android, Thunderbird or solyto's own data export can't be brought in.

## Out of scope

CSV import; merging or de-duplication; the Android phone import; opening `.vcf` files from outside the app; continuous two-way sync with the phone; a "Share contact → solyto" target; iOS.

## Notes

- Agreed (decision `01M8775WWFJV62MYGKTF61CYGM`):
  - Limits: 25 MB / 10 000 contacts.
  - A foreign or unknown `address_book_id` returns 404.
  - No merge, but a card gets a fresh UID if its UID already exists in the target book.
  - vCard 2.1 is converted to 3.0; 3.0 and 4.0 are stored unchanged.
  - Every card is validated or repaired first; a card that still doesn't parse is skipped and counted.
- The CardDAV import gets a lock (one import per user), a `failed` stage and a skipped count, mirroring the calendar `.ics` import (`docs/jobs/archive/amazing_calendar-ics-file-import/`).
- No API lang strings.
- Contract (decision `01M8776XF7D9RKG0605X0EP302`):
  - The upload responds 200 with the import state.
  - The state adds `source` (`carddav` | `file`) and `contacts_skipped`, plus a `failed` stage. The file import runs `started` → `contacts` → `finished` | `failed`.
  - Errors:
    - 409 while an import runs (also for the CardDAV start and select);
    - 429 on the file route only;
    - 404 for a foreign or unknown address book;
    - 422 with field errors for validation, including over 25 MB;
    - 422 without field errors for an unreadable or empty file, or more than 10 000 contacts.
  - A new book's name defaults to the filename, made unique with ` (n)`. No shared address books as a target.
- The phone import and opening a `.vcf` from outside the app are out of scope; they go to app backlog entries. No mobile work (human, `01M8777WX5`).
- Amendment `01M8778FWBNM3XY0EQW637ZCJG`: the file endpoint also accepts an optional `color` (default `#0088CC`); `address_book_id` prohibits `name` and `color`.
