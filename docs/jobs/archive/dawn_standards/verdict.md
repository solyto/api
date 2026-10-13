# Verdict: STANDARDS

id: dawn
reviewer: claude-fable-5-1 (@reviewer)
date: 2026-10-13 (round 2)

Branch `feature/dawn_standards` confirmed; base `main` (`.manigot/manigot.json`).
Diff reviewed: `git diff main...HEAD` — 27 files, all under `docs/`, plus the
one-line `README.md` and one-paragraph `docs/AGENTS.md` repoints. No
application code, config, package or `app/Dav`/`pgsql` change. Since the
round-1 verdict (`5e2afcd`) exactly one content commit landed (`e0a7cd8`);
the two `chore: commit all` commits touch only `tokens.json`. Outside the job
directory the post-verdict delta is `STANDARDS.md` (+14/−6),
`STANDARDS_BACKLOG.md` (+16/−7), `TESTING.md` (2 lines) — all five requested
fixes, nothing else. Working-tree `.env.testing` is still a character device
(`crw-rw-rw- root`), not an edit; `tokens.json` is harness output.

Round-1 findings for TASK-2, 4, 5, 6, 11, 12, 13 (all PASS) stand; they were
not touched. Below, the five round-1 blockers re-verified against the code.

## Round-1 blockers — re-verification

1. **Catch-block count and attribution** — fixed.
   `grep -rhoE 'catch \(\\?[A-Za-z\\]+' app/Api/*/Controllers` → `\Exception`
   16 (`CalendarController` 10, `ContactController` 6), `\RuntimeException`
   6, `ValidationException` 1, typed feed/import 4. The §7.2 check line
   returns 16. `STANDARDS.md` theme 3 and §7.2 now say 16 / two files; the
   new §7.2 paragraph lists `AuthController.php:111,246,331,358` and
   `PasskeyController.php:34,59` as typed status mappings — verified line by
   line (`:111` `ValidationException` → `unauthorized()`, `:246`
   `\RuntimeException` → `match` → 401, `:331` → 400, `:358` → 401;
   Passkey `:34` → 401, `:59` → 422). `AuthController.php:63-66` is indeed
   `\RuntimeException` → `report()` + `serverError($e->getMessage())` and is
   correctly split into its own backlog item. The Rewrites item no longer
   names Auth/Passkey as blocks to delete. `findings-controllers.md` §6 is
   now self-consistent (16 + 11 typed = 27).
2. **Test count** — fixed. `grep -rcE '^\s*(it|test)\(' app tests` sums to
   720; 278/720 = 39 %. Updated in `TESTING.md:95`, `tooling-report.md:23`,
   `findings-tests.md:7,18`, `STANDARDS_BACKLOG.md:185`. No `651` or `43 %`
   remains outside the implementation changelog.
3. **`tests/Pest.php` line refs** — fixed. `toBeOne` at `:18-20`,
   `function something()` at `:66-69`; `STANDARDS_BACKLOG.md:62` and
   `tooling-report.md:67` match.
4. **`uses(RefreshDatabase::class)` count** — fixed.
   `grep -rl ... app/Api/*/Tests | wc -l` → 43; `TESTING.md:34`,
   `STANDARDS_BACKLOG.md:68`, `findings-tests.md:14` match.
5. **Rule 4.2 check line** — fixed. `grep -rn 'app(' app/Api/*/Services`
   hits `ExportService.php:46-60` (15 lines), the three `Library*Service`
   `makeWith` sites and `LibraryRecommender.php:116` `app(AiService::class)`
   — exactly as the reworded check states. New backlog item cites
   `LibraryRecommender.php:116` and the constructor at `:41-43`
   (`LibraryTypeEnum $type`, `User $user`) — verified. Its check line
   `grep -rn 'app(' app/Api/*/Services | grep -vc ExportService` currently
   returns 4 with target 0, consistent with the text.

## Review

TASK-1: PASS
notes: Round-1 citations that failed (`tooling-report.md:23` count,
`:67` Pest.php lines) now reproduce; everything else was verified in round 1.

TASK-2: PASS
notes: Unchanged since round 1.

TASK-3: PASS
notes: `findings-controllers.md` §6 now states 16 report-and-500 sites and
enumerates the 11 typed catches with correct lines and statuses.

TASK-4: PASS
notes: Unchanged since round 1.

TASK-5: PASS
notes: Unchanged since round 1; tenant-isolation finding stands.

TASK-6: PASS
notes: Unchanged since round 1.

TASK-7: PASS
notes: `findings-tests.md` counts (720, 39 %, 43) reproduce.

TASK-8: PASS
notes: Theme 3, §7.2 and §4.2 corrected as requested; the Auth/Passkey
catches are now described under the typed-exception paragraph with correct
lines, and `AuthController:63-66` is called out as the one odd member.

TASK-9: PASS
notes: Rewrites item no longer tells a developer to strip typed catches from
`AuthController`/`PasskeyController`; separate items for
`AuthController.php:63-66` and `LibraryRecommender.php:116` added with
accurate lines and check commands. Mechanical items `:62`/`:68` corrected.

TASK-10: PASS
notes: `TESTING.md:34` (43) and `:95` (278 of 720) corrected.

TASK-11: PASS
notes: Unchanged since round 1.

TASK-12: PASS
notes: Unchanged since round 1.

TASK-13: PASS
notes: Unchanged since round 1; `f68db48` still contains exactly the nine
deletions and two repoints.

## Security

- Tenant-isolation finding on `exists:` foreign ids (`STANDARDS.md` 3.2,
  backlog 0.1): confirmed in round 1, unchanged, still first in the backlog.
- No secret, `.env` content or `DockerSecretHelper` value appears anywhere
  in the diff.
- Nothing under `app/Dav/` or the `pgsql` connection was changed.

## Overall

APPROVED

All five round-1 blockers are fixed and reproduce against the code on this
branch; no other content changed. Nothing must change before merge.

For the owner (not blockers): the eight open questions in `STANDARDS.md`
need decisions — OQ-1 (`@OA` path prefix) and OQ-6 (Pint) gate the two
largest mechanical backlog items. Uncommitted `tokens.json` is harness
output and will be swept by `mg done`.
