# Backlog: stale caches left after the 1.1.0 audit

created: 2026-11-21

## What

From the standards audit (`docs/reports/standards-audit-since-1.1.0-2026-11-21.md`,
§2). The todo workspace, time-tracking project and calendar colour gaps were
fixed on 2026-11-21. These are left:

1. **Tags embedded in other modules' cached lists (24 h).** Renaming or
   deleting a tag only forgets `tags` (`TagService.php:42-58`). These lists
   cache tags inside them and keep the old or deleted tag:
   - Finances: `budgets_v2` (new since 1.1.0)
   - Notes: `notes_v2`, `notes_hidden_v2`, `notes_newest_v2`, `notes_recent`
   - Libraries: `books`, `games`, `movies`, `links`, `quotes`
   - `todos` and the event attachments too, but with a 60 s TTL, so harmless.

   Set aside by the owner (2026-11-21). No service forgets another module's
   keys today, so this needs a decision first:
   - A: `TagService` keeps a list of the tag-embedding keys and forgets them on
     update and destroy, as `UserService::USER_CACHE_KEYS` already lists every
     module's keys. Guard it with one test that renames a tag and checks each
     list.
   - B: each module listens for Tag model events and forgets its own keys
     (a pattern the code doesn't have yet).
   - C: stop caching tags inside the lists.
2. **Forget before the write commits (low).** `ClipboardService::storeFile:76-78`
   forgets before writing; `IntegrationGateway::startTimeTracking:190-202`
   forgets `tt_projects_v2` inside a transaction that hasn't committed. A
   concurrent read can re-cache the old data for the full TTL. `BudgetService`
   does it right (forget after the transaction).
3. **`book_releases` (owner decision).** Its TTL went from 1 to 7 days and it
   isn't forgotten when books change; the nightly `GrabBookReleases` job
   refreshes it. Possibly intended.

The clipboard cleanup job's missing forget is in `clipboard-file-cleanup.md`.

## Suggestion

Decide 1 (A is the smallest change). 2 and 3 only when touching that code.
