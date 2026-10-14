# Implementation: backwards compatibility with frontend

id: variety
developer: @developer
date: 2026-10-14

## Summary

Report-only job. Wrote `docs/reports/frontend-backwards-compatibility-2026-10-14.md`. It covers the API's current compatibility surface, the breaking-change classes in tags 1.0.10 → `main`, strategy options A–E, a proposed release/deploy process (P1–P8), ordered follow-up jobs (J1–J10) and open questions. No application code, config, routes, migrations or deploy files were changed.

## Changes

TASK-1: Current-state findings F1 (versioning, health, OpenAPI), F2 (tokens), F3 (CORS) and F6 (envelope, exception map, deploy, observability), with file:line citations. Correction to the analyst's notes: tokens **do** expire per platform (web 7 days, mobile/desktop 90 days, `AuthPlatformEnum::tokenExpiryDays`) even though `sanctum.expiration` is null. They can be refreshed indefinitely, so old clients still never age out. Login also already records `platform` in the token name.
TASK-2: F4 + F5. The cut-off is the last five tags + `main`, because the F-Droid release date is unknown. Main finding: tag `1.0.14` is on the unmerged `authors` branch and is not an ancestor of `1.0.15`. Concrete examples of every change class: removed endpoints/fields (authors), recipe `ingredients` string→array with a destructive migration, `MusicReleaseImport.id` re-typed, Spotify enum value, `max:50` tightening, `.journal` backfill (cited from the earlier report), `whenLoaded` fields, and changed error messages.
TASK-3: F7 option table (A adopt, B last resort, C adopt as safety net, D not as a general mechanism, E reject), plus a worked expand/contract example for recipes and the "426 trap": header-less installed builds must be allowed.
TASK-4: P1–P8 (linear tags, PR contract rules, expand/migrate/contract, a 90-day / 3-release window proposal, deprecation steps, pre-deploy check, API-before-web deploy order, observation). Items that depend on the deployment repo are marked "verify in solyto/deployment".
TASK-5: J1–J10 with files and risk. DAV/`pgsql` flag: none touch DAV. J4's middleware must be scoped to `api/*`.
TASK-6: Report written in the existing report structure, including open questions and limitations.

## Known issues / follow-ups

- The repo has no CI anymore (`703d82a` removed the test workflow), so the proposed compat check has no home yet (open question 7).
- `.env.testing` shows as modified in the worktree, and git cannot hash it ("unsupported file type"). It was not touched or committed by this job. Its contents were not read.
