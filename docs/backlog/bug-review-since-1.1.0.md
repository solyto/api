# Backlog: bug-focused review of the code changed since 1.1.0

created: 2026-11-21

## What

The standards audit of 2026-11-21 (`docs/reports/standards-audit-since-1.1.0-2026-11-21.md`)
checked the code against `docs/STANDARDS.md`. It was not a bug hunt: the bugs
it found surfaced while checking rules, and nothing was run against a live
app. A dedicated correctness review of what changed since 1.1.0 (215 commits,
657 files at the time) is still open. Much of it was written by AI with little
human review.

The app has its own entry for its side (`solyto/app`
`docs/backlog/bug-review-since-1.1.0.md`).

## Suggestion

- After `dissolve-integration-gateway.md`, so the restructured code isn't
  reviewed twice.
- Scope: `git diff 1.1.0..HEAD -- app routes`, by module. Start where the
  audit saw the most new logic: Calendars (external calendars, DAV backend,
  caching), Feeds (OPML import), Users (account deletion, widget tokens),
  Clipboard files, the MCP tools.
- Look for correctness, not style: error paths, races, timezone handling (the
  bot `/day` bug in `bot-day-timezone.md` is the kind of thing to find),
  cache invalidation (`cache-followups.md`), authorisation.
