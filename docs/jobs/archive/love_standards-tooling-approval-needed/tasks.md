# Tasks: Standards: tooling (approval needed)

id: love
analyst: @analyst
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

## Scope

Branch checked: worktree HEAD is `feature/love_standards-tooling-approval-needed`,
which matches `brief.md`.

The brief lists six backlog items. The owner **approved two**, and only those
are in scope:

1. Larastan (`larastan/larastan`, level 5, `phpstan.neon`, `paths: [app]`,
   `app/Dav` excluded). The owner approved adding this new dev package.
2. An automated check that compares each resource's `@OA\Schema` properties
   with its `toArray()` keys (rule 2.6).

**Out of scope (REJECTED):** `pint.json` / `composer lint` / the repo-wide
Pint run, `.github/workflows/tests.yml`, npm in `.github/dependabot.yml`, and
`pcov` / coverage. None of these files should be created or changed. In
particular, do **not** run `./vendor/bin/pint` on files outside the diff.

## Notes and assumptions (read before implementing)

- **Script or Pest test (rule 2.6 check):** the brief allows either. These
  tasks pick a **Pest test in `tests/Unit/`** for three reasons. A
  `scripts/` directory doesn't exist yet. `tests/Unit/OpenApiSpecTest.php`
  already does a similar annotation-vs-code check with an
  `OPENAPI_KNOWN_DRIFT` allow-list, so the new test can follow that
  pattern. And `docs/TESTING.md:24` already lists "the OpenAPI drift test"
  as a `tests/Unit` job. With a test, `composer test` enforces the check;
  nothing new needs to be wired up.
- **Some drift may still exist.** `findings-data.md` §3
  (`docs/jobs/archive/dawn_standards/findings-data.md`; the path in the
  brief is out of date) listed 10 drifting resources. `TodoResource` has
  since been fixed by the `northern` job, but the rest haven't been
  rechecked. Fixing the drift is **not** in this job's scope. Any drift
  that still exists goes into a known-drift allow-list in the test, the
  same way `OpenApiSpecTest` does it. The 4 `*SearchResultResource`
  classes have no `@OA\Schema` and need the same treatment (skip them or
  list them explicitly).
- **Larastan baseline — unclear, flagged:** the brief doesn't say what to
  do with the errors level 5 will report on `app/`. The tooling report
  expects some (e.g. Libraries services, `User.php:101-126`). Fixing them
  would be a broad refactor, which the project hard rules forbid. These
  tasks assume a generated `phpstan-baseline.neon` included from
  `phpstan.neon` (the standard Larastan approach, and the same idea as
  `OPENAPI_KNOWN_DRIFT`). **If the owner doesn't want a baseline, TASK-2
  has to stop and ask.**
- **`composer analyse` script — unclear, flagged:** the brief doesn't ask
  for a composer script for Larastan. It isn't included as a task. If
  wanted, it is a one-line addition to `composer.json` `scripts`.
- `vendor/` may not exist in the session (see tooling report "Limits"). If
  `composer require` can't run, TASK-1 can't be finished in-session and must
  be reported, not faked by hand-editing `composer.lock`.

## Task breakdown

TASK-1: Add `larastan/larastan` as a dev dependency with `composer require --dev larastan/larastan` (the owner approved it in the brief), so the package and the updated lock file are committed.
     files: composer.json, composer.lock
     depends: none
     risk: medium — this is a new package with transitive dev deps (phpstan). It must resolve against Laravel 12 / PHP 8.4 (Larastan 3.x), and the lock-file diff should be checked so no unrelated packages get upgraded (no `composer update`).

TASK-2: Create `phpstan.neon` that includes Larastan's extension, sets `level: 5`, `paths: [app]` and `excludePaths: [app/Dav]`, and includes a generated `phpstan-baseline.neon` (see the baseline note above), so `./vendor/bin/phpstan analyse` exits 0 on the current tree.
     files: phpstan.neon (new), phpstan-baseline.neon (new, generated with `--generate-baseline`)
     depends: TASK-1
     risk: medium — whether a baseline is acceptable is an open owner decision. Analysing `app/` also boots the Laravel app, which could hit `DockerSecretHelper` / missing secrets, so the developer must make sure no secret values end up in the baseline or the output.

TASK-3: Add a Pest test in `tests/Unit/` that loads every `app/Api/*/Resources/*Resource.php`, collects the `@OA\Property(property="…")` names from its `@OA\Schema` docblock, and compares them with the top-level keys of its `toArray()` array literal. Known exceptions go in an allow-list constant, following the `OPENAPI_KNOWN_DRIFT` pattern, so that only *new* drift fails.
     files: tests/Unit/ResourceSchemaDriftTest.php (new; name is a suggestion)
     depends: none
     risk: medium — static parsing of `toArray()` is heuristic (nested arrays, `whenLoaded`, `merge`/`mergeWhen`, resources without a literal return array). The test must compare only top-level keys, as the throwaway version did, and must not change any resource or annotation.

TASK-4: Run the new test and `composer test`, then fill the allow-list from TASK-3 with the drift that exists today (one entry per resource/key, plus the four schema-less `*SearchResultResource` classes), so the suite is green without touching any resource.
     files: tests/Unit/ResourceSchemaDriftTest.php
     depends: TASK-3
     risk: low — this only changes the test's data. The list is evidence for later cleanup, and `implementation.md` should copy it.

TASK-5: Update the docs that describe these checks. In `docs/STANDARDS.md` 2.6, the "Check:" line (currently "A script … is in the backlog") should name the new test. The `## Decisions` line at `STANDARDS.md:873-875` should say Larastan is adopted while Pint config and CI are not. The `docs/TESTING.md:24` `Unit` row should mention the resource-schema test. In `docs/STANDARDS_BACKLOG.md` → Tooling, tick the two approved items.
     files: docs/STANDARDS.md, docs/TESTING.md, docs/STANDARDS_BACKLOG.md
     depends: TASK-2, TASK-4
     risk: low — docs only. **Unclear:** whether the four REJECTED items should be removed from the backlog, marked "rejected", or left alone. They are left alone unless the owner says otherwise. Also check `docs/STANDARDS_WORKFLOW.md` for how `STANDARDS.md` edits must be made.

## Not planned (needs owner input if wanted)

- A `composer analyse` script for Larastan.
- Fixing any Larastan findings or any resource-schema drift. That belongs in
  follow-up jobs using the baseline and the allow-list as the to-do list.
- Running Larastan in CI (CI was rejected).
