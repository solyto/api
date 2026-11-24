# Tasks: contacts-vcf-and-phone-import

id: steam
analyst: ralphie
date: 2026-11-24

API side only. Contract: room decisions `01M8775WWFJV62MYGKTF61CYGM`, `01M8776XF7D9RKG0605X0EP302`,
`01M8778FWBNM3XY0EQW637ZCJG`. Template: the calendar `.ics` import (`b966dca`).

## Task breakdown

TASK-1: Shared vCard repair helper
- Move the "forgiving parse + re-serialize + drop an unreadable photo" strategy and the strict validity
  check out of `RepairCorruptedVCards` into `VCardHelper`; the command uses the helper.
- files: `app/Dav/Helpers/VCardHelper.php`, `app/Api/Contacts/Commands/RepairCorruptedVCards.php`, tests
- depends: none
- risk: low — behaviour of the command unchanged

TASK-2: Contacts import state and CardDAV import hardening
- `AddressBookImportDTO`: `source` (`carddav`|`file`), target address book id, `contactsSkipped`,
  `updatedAt`, `failed` stage.
- `ContactImport`: one import per user (lock + running check, 409 for start/select while running, stale
  after 5 min), job id ownership, `fail()`.
- Every CardDAV card goes through the card check (TASK-1 repair, 2.1 → 3.0, CardDAV profile validation
  with repair of UID/FN, fresh UID when the UID already exists in the target book); failures are counted
  as skipped, never stored.
- `ImportContacts` job carries the job id, `failed()` sets `failed`.
- `ImportStateResource`: `source`, `contacts_skipped`, `failed` in the schema (additive).
- files: `app/Dav/DTOs/AddressBookImportDTO.php`, `app/Dav/Services/ContactImport.php`,
  `app/Api/Contacts/Jobs/ImportContacts.php`, `app/Api/Contacts/Controllers/ImportController.php`,
  `app/Api/Contacts/Resources/ImportStateResource.php`, tests
- depends: TASK-1
- risk: medium — changes a running import's flow; the old 1.0.15 client contract for the state stays

TASK-3: `.vcf` file import
- `POST /api/v1/address-books/import/file` (throttle `contacts-import`, 20/h): `file` (`.vcf`, ≤ 25 MB),
  `address_book_id` (prohibits `name`, `color`) or `name`/`color`; 404 for a foreign or unknown book.
- File split into cards, ≤ 10 000 cards, unreadable/empty → 422; stored on the `local` disk and always
  deleted; queued `ImportContactsFile` job (`tries = 1`, `timeout = 300`).
- New book: name from `name`, else the filename, unique with ` (n)`.
- files: `routes/api.php`, `app/Api/Contacts/Requests/ImportContactsFileRequest.php`,
  `app/Api/Contacts/Jobs/ImportContactsFile.php`, `ImportController`, `ContactImport`,
  `AppServiceProvider` (limiter), tests + fixtures
- depends: TASK-2
- risk: medium — large uploads; covered by limits and the queue
