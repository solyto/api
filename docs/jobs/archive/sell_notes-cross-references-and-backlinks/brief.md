# Brief: notes-cross-references-and-backlinks

type: feature
id: sell
branch: feature/sell_notes-cross-references-and-backlinks
date: 2026-11-24
author: Leander Muskalla

## What

API part of the backlog entry `notes-cross-references-and-backlinks` (app repo,
`docs/backlog/notes-cross-references-and-backlinks.md`): a note can link to
another note with `<a href="/notes/{uuid}">`; the API keeps a `note_links`
table up to date on every content write (create, update, import) and serves
`GET /api/v1/notes/{note}/backlinks`. Existing links are backfilled once by a
data migration.

## Why

The linked note shows a "Linked from" list. The server computes the links so
backlinks are correct for every app version and for imported notes.

## Out of scope

- All app/UI work (separate repo, charmaine).
- Hidden notes and the journal as link sources (decision 7).
- Rewriting links on rename, delete, export or import (decisions 2, 6, 8).
- Graph view, unlinked mentions, links to other item types.

## Notes

Room decisions (2026-11-24): backlinks contract `data: [{id, title}]`, sorted
by title, hidden-category sources left out, 403 for another user's note,
404 for an unknown id; backfill as a data migration with a
`MigrationCompatibilityTest` allowlist entry. Content is HTML (TipTap) or
older Markdown; links are matched as `/notes/{uuid}` in the raw content,
including absolute URLs.
