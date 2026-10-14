# Brief: Hidden note categories are opt-in

type: feature
id: virus
branch: feature/virus_hidden-note-categories-are-opt-in
date: 2026-10-14
author: Leander Muskalla

## What
The plain notes endpoints return only visible folders and the notes in them. Hidden folders and their notes come from separate endpoints that only the new frontend calls (`docs/STANDARDS.md` 13.3).

- `GET notes/categories` leaves out every category with `is_hidden` or `is_system` set, and every descendant of one. Today it returns `.journal` to every client (`NoteCategoryService::listRoots`, roots with `descendants`).
- `GET notes` leaves out notes whose category is one of those (`NoteService::list`). `GET notes/newest` already filters `is_hidden`, but only on the direct category; make it use the same rule.
- New `GET notes/categories/hidden` returns the left-out categories as a tree (same resource).
- New `GET notes/hidden` returns the left-out notes (same resource).
- Register both new routes before `apiResource('categories', …)` and `apiResource('/', …)`, so `hidden` is not bound as `{category}` or `{note}`.
- Each list gets its own cache key under `UserCacheService`. Every write that changes a category, a note or `is_hidden` forgets all of them (rules 8.1, 8.2, 4.4).
- OpenAPI annotations for the two new routes (section 2).

## Why
`2026_10_10_000001_backfill_journal_categories` creates `.journal` for every user, and `UserObserver` creates it for every new user. A 1.0.15 app (F-Droid) doesn't know `is_hidden`/`is_system`. It shows the folder in the notes navigation, and rename and delete fail silently (403 → `ApiService` returns `false`). Journal entries written in the new web app would show up there as notes titled by date. This breaks rule 13.1: an old frontend must not look weird.

## Decisions
- Separate endpoints, not a query flag. This was the owner's preference on 2026-10-14.
- Filter on `is_hidden OR is_system`, so `.journal` stays out even if a user un-hides it, because edits to it still return 403.

## Out of scope
- The export (`NoteExportService`): it is a file the user asked for, so it keeps everything.
- Notes attached to calendar events (`EventAttachmentService`): those are attached explicitly.
- Admin counts in `StatisticsService`.
- The backfill itself and `journal/marks`.

## Notes
**Frontend coordination (`solyto/app`, not done from this repo):** 1.0.15 doesn't know `.journal`; hiding it is the fix for 1.0.15. Frontend `main` is unreleased, so it is not a supported build and can change freely, but it must switch before its first release. `main` (`8d70018`) finds `.journal` in the plain list (`src/lib/state/Notes.svelte.ts:98-103`, `src/lib/services/JournalService.ts:9-11`), and finds journal entries in the plain notes list. It must also load `notes/categories/hidden` and `notes/hidden` and merge them in. Ship this API change together with that frontend change, before the next frontend release. The web frontend deploys in lock-step with the API, so deploy them together.

Tests:
- In `tests/Unit/ClientContractTest.php` ("Since 1.0.15"): the plain categories list contains no `.journal` and no hidden descendant, and the plain notes list contains no note from one.
- In the Notes API test: the hidden endpoints return them, tenant isolation holds, and the cache is forgotten after `PATCH categories/{category}/hidden`.

Check: `composer test` and `composer analyse` exit 0.
