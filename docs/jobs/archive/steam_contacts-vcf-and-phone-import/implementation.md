# Implementation: contacts-vcf-and-phone-import

id: steam
developer: ralphie
date: 2026-11-24

## Summary

API side of the contacts file import. Users can upload a `.vcf` file into a new address book or one of their own. The file is split per card, and each card is checked the way sabre checks a CardDAV PUT before it is stored raw. The existing CardDAV account import now goes through the same card check, and gets the calendar import's one-import-per-user rule, `failed` stage and skipped count. Contract: room decisions `01M8775WWFJV62MYGKTF61CYGM`, `01M8776XF7D9RKG0605X0EP302`, `01M8778FWBNM3XY0EQW637ZCJG`. No migrations, no DAV schema or route changes, no new lang strings.

`composer test` passes (2 403 tests) and `composer analyse` reports no errors.

## Changes

TASK-1 (shared repair helper):
- `VCardHelper::isValid()`, `repair()` (forgiving parse, re-serialize, drop an unreadable photo) and `dropUnreadablePhoto()`, moved out of `RepairCorruptedVCards`. The command uses them; its strategy 2 is unchanged.
- Tests in `tests/Unit/Dav/VCardHelperTest.php`.

TASK-2 and TASK-3 are in one commit, because both live mostly in `ContactImport`.

TASK-2 (state and CardDAV hardening):
- `AddressBookImportDTO` gains `source` (`carddav` | `file`), `targetAddressBookId`, `contactsSkipped`, `updatedAt` and the `failed` stage. States cached before the deploy read as `carddav`.
- `ContactImport`:
  - `claim()` (a cache lock plus a running check) for the CardDAV start and the file import.
  - `select` answers 409 while an import runs, and 404 for a file import's state.
  - A running import counts as stale after 5 minutes without progress.
  - `fail()`.
- Every card, from CardDAV or a file, goes through `storeCard()`:
  - strict parse, else `VCardHelper::repair()`;
  - vCard 2.1, or no known `VERSION`, is converted to 3.0, and the leftover `CHARSET` and `ENCODING=BASE64` parameters are normalised;
  - CardDAV-profile validation with repair (adds a missing `UID`, builds `FN` from `N`/`ORG`/`NICKNAME`/`EMAIL`);
  - a fresh UID when the UID already exists in the target book or earlier in the same import;
  - stored with `Contacts::import()`, so photos are still scaled.
  - A card that fails any step is logged to the `dav` channel and counted in `contacts_skipped`, never stored.
- `ImportContacts` job: carries the job id, runs only its own `carddav` state, and its `failed()` sets `failed`. Its `Log::info` traces are gone (rule 4.9).
- `ImportStateResource`: adds `source` and `contacts_skipped`, and the stage enum in the schema. Both are additive.

TASK-3 (file import):
- `POST /api/v1/address-books/import/file`, throttled by `contacts-import` (20 per hour).
- `ImportContactsFileRequest`:
  - `file`: required, `.vcf`/`.vcard`, at most 25 MB.
  - `address_book_id`: integer, prohibits `name` and `color`.
  - `name`, `color`: optional.
- Another user's or an unknown book returns 404 through `ContactService::getAddressBookOrFail`.
- `startFile()`:
  - splits the file line by line (BOM, CRLF/LF/CR, a nested 2.1 `AGENT` card);
  - answers 422 for no cards or more than 10 000;
  - names a new book after `name`, else the filename. The name is made unique against display names **and** URI slugs, so `contacts.vcf` becomes "contacts (1)" instead of colliding with the default book's `contacts` URI.
  - The file sits on the `local` disk under `contact-imports/` and is always deleted.
- `ImportContactsFile` job: `tries = 1`, `timeout = 300`, `failed()` → `failed`. The job id guards against a stale job.
- Fixtures, hand-written from each app's documented export format: `tests/Fixtures/dav/{google-export,apple-export,android-export-v21,thunderbird-export-v4,nextcloud-export}.vcf`.
- Tests (`ContactFileImportApiTest`, 37 tests):
  - new and existing book, name and colour, name numbering, slug collision;
  - another user's or an unknown book;
  - validation, empty, unreadable or too many contacts;
  - a missing UID or FN, a broken card among good ones, duplicate UIDs across two imports;
  - a photo, each app's export, the 2.1 → 3.0 conversion;
  - a round trip through solyto's own export, `addressbook-multiget` on an imported book;
  - 409 and stale imports, a failed or stale job, a deleted target.
- `DavImportTest` covers the CardDAV card check, 409, select on a file state, the state fields, and job ownership and failure. `docs/TESTING.md` coverage map updated.

Review fix (verdict `a3bc71a`): `splitFile()` treated every `BEGIN:VCARD` inside an open card as a nested 2.1 `AGENT` card, so a card without `END:VCARD` swallowed all later cards, and a card cut off at the end of the file was dropped without being counted. Now:
- a nested `BEGIN:VCARD` counts only directly after an `AGENT` line;
- any other one closes the open card and starts a new one;
- a card still open at the end of the file is returned too.
Such a card then goes through `storeCard()`. If the only thing missing is `END:VCARD`, sabre reads it and it is imported, rather than throwing away a complete contact. Otherwise it is skipped and counted. Tests cover both, in the middle and at the end, plus an `AGENT` card.

## Known issues / follow-ups

- **CardDAV account import, not touched (scope):** it still calls `Http` directly instead of `RemoteCalDavClient` (no `OutboundUrlGuard`, no response size cap). It also keeps the account secret in plaintext in the import state cache. The calendar import fixed both. Worth its own item.
- `ContactService::createAddressBook` (manual "new address book") has the same slug collision. Creating "contacts" next to "Contacts" fails on the unique `(principaluri, uri)` index.
- `timeout = 300` with 10 000 contacts leaves about 30 ms per card. Photo scaling on large phone exports could run past it; the import then ends in `failed`, with the cards already stored kept.
- vCard fixtures are synthetic. Real exports from users would be a better check, especially of 2.1 output from different phone makers.
- `AddressBookImportDTO::$stage` keeps the pre-existing bitwise-OR "default" (same as the calendar DTO).
- `.env.testing` was already modified in the worktree and is left uncommitted. `docs/jobs/…/melfi-status.md` is Melfi's and was not committed by me.
