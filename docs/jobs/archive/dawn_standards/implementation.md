# Implementation: STANDARDS

id: dawn
developer: claude-fable-5-1
date: 2026-10-13

## Summary

Documentation-only job. The frontend's standards set was replicated for the
API: `docs/STANDARDS.md` (12 numbered sections, 58 rules with file:line
examples and grep checks, Decisions, 8 open questions), `docs/STANDARDS_BACKLOG.md`
(sized checklist, security item first), `docs/TESTING.md` (rewritten from
verified facts), `docs/STANDARDS_WORKFLOW.md` (how to resume), plus the
job artefacts: a tooling/testing inventory, a human-vs-AI provenance map and
five findings files with the raw counts. The AI-written policy set from
2026-10-10 (`docs/API-CONVENTIONS.md` + eight `docs/CONVENTIONS-*.md`) was
verified rule by rule, folded into `STANDARDS.md` §12 and deleted in its own
commit; `docs/AGENTS.md` and `README.md` now point at the new set.

No application code, no package, no config was changed. `app/Dav/` and the
`pgsql` connection were described, not touched. No secret was read or
printed.

One security finding surfaced and is confirmed end to end (`STANDARDS.md`
3.2, backlog item 0.1): 40 `exists:table,id` rules accept another user's
category/tag/genre/folder id; `TodoService::create` and siblings store it
and the resource serialises the foreign row's name back. Integer ids make
it guessable. Fix shape exists in the owner's own code
(`EventAttachmentController:138`, `TimeTrackingEntryController:167-170`).

## Changes

TASK-1: `docs/jobs/dawn_standards/tooling-report.md` — what exists
(Pest 4.4.1 unpinned, Pint 1.27.1 with no config and never run, `.editorconfig`,
Dependabot composer-only, empty `<coverage/>`), what is missing (static
analysis, CI since `703d82a`, pinned test deps, npm Dependabot, AGPL
metadata), and six recommendations all marked as needing approval.
`vendor/` is absent in the session, so Pint/`composer test` could not be
run; Pint claims are grep-based against the upstream `laravel` preset.

TASK-2: `docs/jobs/dawn_standards/provenance.md` — git cannot separate
human from AI by author (all commits carry the owner's name); attribution
uses the nine `docs/jobs/archive/*` briefs, commit-message style and burst
size. Module → verdict → reference file table. Key result: the OpenAPI
annotation split is the owner's June style vs later style, not human vs AI.

TASK-3: `docs/jobs/dawn_standards/findings-controllers.md` — prefix drift
186/82, inline envelope 137 in 20 files, `ValidationErrorResponse` 54,
duplicate `operationId`s `search`×4/`import`×5, 22 inline `validate()`
sites classified by size, 27 controller catches (22 of the
report-and-500 kind), duplicated recommend branches, health route
`env()`, route-file shape, unregistered `route('login')` in `bootstrap/app.php:97`.

TASK-4: `docs/jobs/dawn_standards/findings-services.md` — credits first
(uniform shape, `UserCacheService`, `IntegrationGateway`, `QuickAddService`
match), then cache-forget pairs, `call_user_func`/`makeWith`
(`LibraryReleases` constructor user), `findGenre` pass-throughs, `mixed`/`?array`,
the Libraries ×4 copy, export CSV boilerplate, `Log::info`, swallowed
catches, methods > 60 lines.

TASK-5: `docs/jobs/dawn_standards/findings-data.md` — the `exists:` trace
table (14 rows, 2 correct, 11 unchecked, 1 by design), FormRequest style,
resources without schema (4) and schema↔`toArray` drift (10, incl. the
owner's `TodoResource`), `whenLoaded` misses (3), model facts
(HasUuids 22/52, casts style, untyped relations in `User.php`).

TASK-6: `docs/jobs/dawn_standards/findings-background.md` — jobs
(`$tries`/`$timeout` on 2/4 of 18, four jobs carrying `User` without
`SerializesModels`), schedule and command registration verified, DAV/Bots
catch blocks described only.

TASK-7: `docs/jobs/dawn_standards/findings-tests.md` — 720 tests: 278 (39 %)
factory/model tests, API-contract and service layers, tenant-isolation
coverage per file (11 API test files without), helper usage (two helpers
and one expectation unused), skeleton residue.

TASK-8: `docs/STANDARDS.md` — sections 1–11, Decisions, Open questions.
Framework-dependent rules cite the Laravel 12 docs (`env()` under
`config:cache`, `Rule::exists()->where()`) and Pint's `laravel` preset
source, read on 2026-10-13.

TASK-12: `docs/STANDARDS.md` §12 "Architecture conventions" (10 items, each
re-verified with a file reference) and a Decisions list of the eleven old
rules that were dropped or corrected and why.

TASK-9: `docs/STANDARDS_BACKLOG.md` — First (3), Mechanical (17), Extract
(7), Rewrites (9), Larger (4), Audits (5), Tooling (6, all "(approval)").
Every item names a rule and most carry a check line.

TASK-10: `docs/TESTING.md` — runner, the three suites + two Pest-only dirs,
`TestCase::createApplication` facts, helpers (with the unused ones named),
what gets a test and what deliberately does not, caveats. Verified against
`tests/TestCase.php`, `phpunit.xml`, `tests/Pest.php`, `app/Api/Pest.php`.

TASK-11: `docs/STANDARDS_WORKFLOW.md` — goal, roles, what the owner said,
shape, resuming, state at the end of 2026-10-13.

TASK-13: deleted `docs/API-CONVENTIONS.md` and the eight
`docs/CONVENTIONS-*.md`; repointed `docs/AGENTS.md` (Conventions paragraph)
and `README.md:46`. Separate commit (`f68db48`) so it can be reverted alone.
Remaining `CONVENTIONS` mentions are only in archived job folders
(historic) and in this job's own notes.

## Review round (2026-10-13, verdict NEEDS WORK)

Five citation fixes, nothing else changed:

1. Catch-block count: the `catch (\Exception) { report(); return 500 }` shape
   is 16 sites (`CalendarController` 10, `ContactController` 6), not 22;
   `AuthController`/`PasskeyController` removed from that count and from the
   backlog delete-item, listed under the typed-exception paragraph instead
   (`STANDARDS.md` theme 3 + §7.2, `STANDARDS_BACKLOG.md` Rewrites,
   `findings-controllers.md` §6). `AuthController:63-66` kept as its own item.
2. Test count 651 → 720, factory-test share 43 % → 39 % (`TESTING.md`,
   `tooling-report.md`, `findings-tests.md`, `STANDARDS_BACKLOG.md`, here).
3. `tests/Pest.php` refs `:17-19`/`:58-61` → `:18-20`/`:66-69`
   (`STANDARDS_BACKLOG.md`, `tooling-report.md`).
4. `uses(RefreshDatabase::class)` in `app/Api/*/Tests`: 45 → 43
   (`TESTING.md`, `STANDARDS_BACKLOG.md`, `findings-tests.md`).
5. Rule 4.2 check line now names the `Library*Service` `makeWith` hits and
   `LibraryRecommender.php:116` as a violation; backlog item added.

## Known issues / follow-ups

- `.env.testing` shows as modified in `git status`; it is a character
  device in this session (`crw-rw-rw-`, unhashable), not an edit. Nothing
  to commit there.
- The eight open questions in `STANDARDS.md` need the owner (OQ-1 path
  prefix and OQ-6 Pint gate the two largest mechanical items).
- A tracked user-data export zip (`tmp/export_…zip`, since `072fea9`) and
  generated `bootstrap/cache/*.php` (since `b10b48c`) are in the backlog's
  first group; not removed here because it is a code/history change.
- `findings-*.md` line numbers are a snapshot of 2026-10-13; they will
  drift as the backlog is worked.
