# Brief: notebook: journal folders

type: feature
id: home
branch: feature/home_notebook-journal-folders
date: 2026-10-10
author: Leander Muskalla

## What

Introduce the backend mechanic for the personal journal: a protected, hideable
notes folder per user that the (separate) frontend surfaces as a special,
date-based Journal interface rather than a normal folder.

- Add two boolean columns to `note_categories`: `is_system` (server-managed,
  default false) and `is_hidden` (visibility, default false).
- Provision exactly one journal folder per user: `title = '.journal'`,
  `is_system = true`, `is_hidden = true`, root-level (`parent_id = null`). Do it
  for new users in `UserObserver::created` and backfill existing users with a
  migration; skip users that already have one.
- `is_system` folders are immutable to the user: rename (update) and delete are
  both rejected with 403. `is_system` is never settable through the API.
- `is_hidden` is a general folder property, toggled through a new dedicated
  endpoint `PATCH /v1/notes/categories/{category}/hidden` with `{ "hidden": bool }`,
  allowed on any folder including system ones. (The frontend won't expose it for
  ordinary folders yet.)
- Expose both flags on `NoteCategoryResource` and in the OpenAPI `NoteCategory`
  schema.
- Exclude notes whose folder is `is_hidden` from `GET /v1/notes/newest`.
  `GET /v1/notes` stays complete (the journal UI reads day entries from it);
  favorites filtering stays client-side.
- `GET /v1/notes/categories` keeps returning the journal folder so the client
  can resolve its id.

## Why

Give every user a durable, protected home for day-based journal entries without
changing how notes are stored, so the frontend can build the Journal interface on
a reliable folder identity.

## Out of scope

- All frontend work (notebook navigation, day UI, datepicker, read-first vs
  write-first) — it lives in the separate frontend repository.
- Calendar day-marks / check-in linking (direction step 2) and the planner (later
  step).
- Recursive hiding of descendant folders: the API applies `is_hidden`
  per-folder; what that means for a subtree is a frontend concern.
- Adopting, renaming or converting pre-existing user folders named `.journal`.
- Any change to note storage, note CRUD, note search, or export contents.

## Notes

- No journal-specific note endpoint. Journal entries are ordinary notes living in
  the journal category, titled by date (title = date comes from the
  connectivity-direction convention). Retrieval is by day, in the frontend.
- Identity of the journal, if ever needed server-side, is the title `.journal`;
  `is_system` keeps that title from being changed.
- Export already walks every category and note, so the journal is included
  automatically. `.journal` sanitizes to `journal/` in the archive (leading dot
  trimmed by `NoteExportService::sanitizeFilename`) — accepted.
- Known fallout from provisioning a folder for every user: `NoteApiTest` category
  count assertions (currently lines ~85 and ~96) and `StatisticsService`'s
  `note_folders` count both shift.
- The migration must not be run without showing it first (repo hard rule)