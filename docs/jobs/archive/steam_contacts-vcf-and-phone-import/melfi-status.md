# Melfi status — contacts-vcf-and-phone-import

## Why you haven't heard from me

This session has no shell, only file read, search, edit and web tools. I can't run `room post`,
`room get` or `git`. Everything I wrote went into my session output, which you can't see.
**I need shell access for this session**, or you relay for me. Until then, this file is my
channel to you.

## What I did

- Read the backlog report `docs/backlog/contacts-vcf-and-phone-import.md`. It's the spec;
  the job's `brief.md` is still empty.
- Read the calendar `.ics` import job (`docs/jobs/archive/amazing_calendar-ics-file-import/`)
  and the contacts import code. The calendar import is the template: an endpoint, a queued
  job, an import state with a `failed` stage and a skipped count, one import per user at a
  time (409), a temp file that is always deleted, and no API lang strings.
- The "contacts export loses data" fix has already landed, so testing export then re-import
  makes sense. It is not part of this job. Tony confirmed it doesn't affect the app.

## Update 16:46

Your decision with Ralphie (`01M8775WWFJV62MYGKTF61CYGM`) settles questions 2–6 below.
I wrote it into `brief.md`. Still open: **1 (is the phone import in this job?)**, 7 (colour),
8 (test files), whether shared address books are allowed as a target, and whether the
200 response carries the initial state like calendars. Tony can start on the store and
modal-shell rework now; the API calls wait for Ralphie's full contract.

## Update 17:03: formal procedure

API implementation is done (`implementation.md`, `tasks.md`; 2 398 tests green per Ralphie).
Next: the API reviewer reviews `main...feature/steam_contacts-vcf-and-phone-import` against
the three contract decisions, `STANDARDS.md` and `TESTING.md`, runs the full `composer test` and
`composer analyse`, and writes `verdict.md`. The app reviewer does the same in the app repo once
Tony reports done.

Product verdict (API): **SHIP**, with one point for the reviewer:
- **Large imports may time out.** The job has `timeout = 300` for up to 10 000 contacts with
  photos, about 30 ms per card. A big phone export can end in `failed` with only part of the
  contacts stored. There's no de-duplication, so re-importing that file duplicates the part
  that did get in. That's the worst outcome for a non-technical user. The reviewer should
  measure a large fixture (10 000 cards with photos). If it doesn't fit, raise the timeout
  (with `retry_after`) or lower the contact limit before merge.
- Follow-ups to put in the backlog, not in this job: the CardDAV import's plaintext secret in
  the cache and missing `OutboundUrlGuard`; the slug collision in manual "new address book".

## Questions for you (my recommendation in brackets)

1. **Scope:** `.vcf` file import (API and app) now, and the Android phone import as a
   separate job? [Yes. The phone import needs a mobile developer, a designer for the
   screen that lists phone accounts and asks for confirmation, a new permission and maybe
   a dependency. None of that should block the `.vcf` import.]
2. **Harden the existing CardDAV contacts import in this job?** It has no "one import at a
   time" rule, no `failed` stage, and it stores cards without checking them. [Yes, as the
   calendar job did.]
3. **Limits:** [25 MB and 10,000 contacts. Ralphie proposed this; phone exports with
   photos are big.]
4. **Someone else's address book:** [404, like calendars; 403 for shared ones.]
5. **Card whose UID already exists in the target address book:** [Give it a new UID.
   We still don't de-duplicate, but CardDAV sync doesn't break.]
6. **vCard 2.1:** [Convert to 3.0 on import.]
7. **Colour for a new address book:** [No colour picker in the import; the server
   default `#0088CC` applies and the user can change it later.]
8. **Test files:** can you provide real exports (Google, Apple, Android, Thunderbird,
   Nextcloud), anonymised, or should Ralphie build synthetic ones?

## Agents

- **Translator:** needed on the app side.
- **Designer and mobile developer:** only if the phone import stays in this job.

## What the team does next, once 1–3 are answered

- **Ralphie:** writes the API contract (`POST /address-books/import/file`; the response
  carries the initial import state; `failed`, `source`, `contacts_skipped`;
  403/404/409/422/429). It is recorded as a room decision.
- **Tony:** reworks the import screen into a store and a modal shell like the calendar
  import, with "CardDAV account" and ".vcf file" options. He builds the API calls after
  the contract.
