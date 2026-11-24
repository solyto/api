# Verdict: contacts-export-loses-data

id: lesson
reviewer: reviewer
date: 2026-11-24

## Review

Base: `main` (`.manigot/manigot.json`). Diff `main...HEAD` touches only the five files named in tasks.md plus the
job docs; no `docs/policies/` or `docs/AGENTS.md` changes.

TASK-1: PASS
notes: `app/Dav/DTOs/ContactDTO.php:16-17` — `public ?string $storedData = null` with a why-comment, mirrors `EventDTO`.

TASK-2: PASS
notes: `app/Dav/Backend/AppContactsPDO.php:135-151` — read-only `SELECT id, uri, carddata` on the existing `cards`
table, generator, resource → string, `fromSabre()` with photo included. `app/Dav/Services/Contacts.php:24-27`
pass-through. `getContactsCustom()`/`list()` and the Redis list cache are untouched. Raw PDO stays inside `app/Dav`
(STANDARDS 5.5); no change to the `pgsql` connection, schema or DAV routes, and implementation.md flags the backend
addition as the hard rule requires.

TASK-3: PASS
notes: `app/Api/Export/Services/ContactExportService.php` — iterates every address book, writes the parsed stored
`VCard` via `serialize()` (ends in CRLF), falls back to `toVCard()."\r\n"` on missing/unparseable data. The
`\Throwable` catch has a why-comment, same as `CalendarExportService::readStored()` (STANDARDS 4.8). Writing card by
card to a handle with `fclose` in `finally` keeps memory bounded. A user with no contacts still gets an empty file.

TASK-4: PASS
notes: `app/Api/Export/Tests/ContactExportServiceTest.php` covers all five cases from the brief, plus the empty file:
multi EMAIL/TEL/ADR with TYPE, BDAY, URL and X- property; a contact created through the app; PHOTO; an unreadable
stored card (inserted directly on `pgsql` in a test, which is allowed) falling back to the rebuilt card; and two
address books parsed with `Sabre\VObject\Splitter\VCard`. File-local helper functions follow the existing test files.

TASK-5: PASS
notes: I ran both in the worktree. `composer test` exits 0 (whole suite, 8919 assertions), and `composer analyse`
exits 0 with "[OK] No errors". Every test, on `main` too, warns that `/workspace/.env` is missing. That comes from the
environment, not from this job.

## Security

none — the new query is a parameterised SELECT scoped by `addressbookid` of the user's own address books; no secrets
in the diff.

## Overall

APPROVED

No blockers. The export now writes the stored vCards, with the fallback the brief asked for, and tests and analysis are green.
