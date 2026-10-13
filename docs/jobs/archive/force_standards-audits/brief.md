# Brief: Standards: audits

type: feature
id: force
branch: feature/force_standards-audits
date: 2026-10-13
author: Leander Muskalla

From `docs/STANDARDS_BACKLOG.md` → "Audits (read, then decide per hit)". Rule numbers refer to
`docs/STANDARDS.md`; counts are from 2026-10-13. Items marked **(approval)**
need the owner's go-ahead first.

- [ ] 22 inline `$request->validate()` sites against rule 3.1 — all but
      `TimeTrackingEntryController:158` are ≤ 3 fields; confirm none is an
      `update`.
- [ ] 14 jobs on default `$tries`/`$timeout`; decide for `Grab*Releases`,
      `ImportCalendars`, `ImportContacts`, `ScaleCovers` (rule 9.2).
- [ ] `ShareCalendarRequest` `friend_id` vs the `Friend` model — OQ-7.
- [ ] Methods over 60 lines outside DAV (`BggService::getGameDetails` 75,
      `RepairCorruptedVCards::handle` 73, `PasskeyService::register` 70 /
      `authenticate` 68, `ImportFeeds::importFromGit` 65, `TodoService::parse` 63):
      each is a parser or a protocol step; split only if a second caller
      appears.
- [ ] `routes/api.php` `->name('x.')` on seven groups with no caller: keep
      or drop uniformly (rule 1.4).

Your goal is to write a report to docs/reports/. We'll handle actual issues in another job.