# Verdict: contacts-vcf-and-phone-import

id: steam
reviewer: richie
date: 2026-11-24

Reviewed `git diff main...HEAD` (24 files) against tasks.md, the room decisions `01M8775WWFJV62MYGKTF61CYGM`,
`01M8776XF7D9RKG0605X0EP302`, `01M8778FWBNM3XY0EQW637ZCJG`, `docs/STANDARDS.md` and the policies.
`composer test`: 2 398 tests, exit 0 (every test shows the same `file_get_contents(.env.testing)` warning, because
`.env.testing` is mounted as a character device in this sandbox; not caused by this branch). `composer analyse`: no errors.

## Review

TASK-1: PASS
notes: `VCardHelper::isValid/repair/dropUnreadablePhoto` are a straight move; `RepairCorruptedVCards` strategy 2
is unchanged. The narrating `// fall through to strategy 2` comment is gone (rule 10.1).

TASK-2: PASS
notes: The lock plus running check (`claim`), the 409 on start and select, the 5-minute stale rule, job-id ownership,
`failed()` → `failed` and the card check for CardDAV cards all match the task. `ImportStateResource` changes are
additive only (rule 13.2). `Log::info` traces removed (rule 4.9). Each `catch (\Exception)` either skips a card or
probes, with a why-comment (rule 4.8).

TASK-3: PARTIAL
notes: The route, throttle, request rules (25 MB, `.vcf`/`.vcard`, `address_book_id` prohibits `name`/`color`),
404 via `getAddressBookOrFail`, the 422 errors with and without field errors, the unique name (display name and
slug), the temp file that is always deleted, and the job (`tries = 1`, `timeout = 300`, with the reason, rule 9.2)
all match the contract and the calendar precedent.
Blocker: `app/Dav/Services/ContactImport.php` `splitFile()` (around lines 375–405 of the new file) silently loses
contacts when one card has no `END:VCARD`. The depth counter treats every following `BEGIN:VCARD` as a nested AGENT
card, so all later cards are merged into the unfinished one. At the end of the file, the leftover lines are thrown
away without being counted. I checked this against the branch code:
- `good a, broken (no END), good b, good c` → 1 card. `b` and `c` are lost, `contacts_count` = 1,
  `contacts_skipped` = 0, and the import reports `finished`.
- `good a, good b, broken` at EOF → 2 cards. The broken card is neither counted nor skipped.
This breaks the agreed rule "a card that still doesn't parse is skipped and counted" (brief, decision
`01M8775WWFJV62MYGKTF61CYGM`), and the user isn't told that data was lost.

Not blockers, for the human/deployment:
- The 25 MB limit only returns 422 if PHP `upload_max_filesize`/`post_max_size` and nginx `client_max_body_size`
  in the deployment repo allow more than 25 MB (the calendar import uses 10 MB). Otherwise large uploads get a
  413 (`PostTooLargeException`), not the agreed 422.
- The developer's follow-ups in implementation.md are valid: the CardDAV account import still uses `Http` directly
  instead of `RemoteCalDavClient`, and the secret is still in the state cache.

## Security

Not run.

## Re-review (7d2ec0e)

`composer test`: 2 403 tests, exit 0 (the same sandbox `.env.testing` warning as before). `composer analyse`: no errors.

The `splitFile()` fix works. A nested `BEGIN:VCARD` now counts only right after an `AGENT` line. Otherwise the open
card is closed, and a card still open at EOF is returned. The new tests cover readable and unreadable cut cards, in
the middle and at the end, with asserted counts, plus an AGENT card. Ralphie's deviation is accepted: a card whose
only defect is the missing `END:VCARD` is imported. Sabre parses it strictly, and the brief only skips cards that
don't parse.

TASK-3: PASS once the item below is fixed.

Blocker: the new comment at `app/Dav/Services/ContactImport.php:369` says such a card "is closed and later skipped".
The code and the tests import it when it parses. A comment that contradicts the code breaks rule 10.1 (a comment
says why, and must be true) and will mislead the next reader. Say that the card is read on its own: imported if it
parses, otherwise skipped and counted.

## Re-review (20cf4dc)

This commit changes only the comment at `ContactImport.php:368-369`, which now matches the code. Code and tests are
unchanged since the green run at 7d2ec0e (2 403 tests, analyse clean). TASK-3: PASS.

## Overall

APPROVED

All tasks pass. The blockers from the earlier rounds are fixed (7d2ec0e: `splitFile()`; 20cf4dc: the comment).
