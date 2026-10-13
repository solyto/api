# Implementation: Standards: tooling (approval needed)

id: love
developer: @developer
date: 2026-10-13

## Summary

Only the two approved tooling items were implemented. The four rejected ones
(Pint config/run, CI workflow, npm Dependabot, pcov) were not touched.

1. **Larastan** (`larastan/larastan` v3.13.0, which pulls in `phpstan/phpstan`
   2.3.1 and `iamcal/sql-parser` v0.7). It runs at level 5 on `app/`, with
   `app/Dav` excluded and today's errors stored in a generated baseline.
   `./vendor/bin/phpstan analyse --memory-limit=2G` now exits 0.
2. **Rule 2.6 check:** a Pest test, `tests/Unit/ResourceSchemaDriftTest.php`.
   It compares each resource's top-level `@OA\Schema` properties with the
   top-level keys of its `toArray()` array literal. It uses the same
   allow-list pattern as `OpenApiSpecTest`.

## Changes

TASK-1: Ran `composer require --dev larastan/larastan`. `composer.json` got one
new line (`"larastan/larastan": "^3.13"`). `composer.lock` gained exactly 3
new packages, with 0 updates and 0 removals.

TASK-2: Added `phpstan.neon` (includes the Larastan extension and the baseline,
`level: 5`, `paths: [app]`, `excludePaths: [app/Dav, …]`) and a generated
`phpstan-baseline.neon` (2380 errors, 1011 entries). The largest groups are
`property.notFound` (684), `method.notFound` (172) and `argument.type` (88).
Many of them come from the Pest tests under `app/Api/*/Tests`, because
`paths: [app]` includes them. I checked the baseline for secrets: it holds
only class, property and method names, no values.
**Deviation from the plan:** PHPStan reports two errors as *non-ignorable*
(`return.missing`), so a baseline cannot hold them. Both are empty stub
methods, i.e. real bugs:
- `NotesImportExportController::export(): JsonResponse {}`. This method is
  routed (`GET notes/export`), so the endpoint would throw a TypeError at
  runtime.
- `TelegramBotConnectionController::confirmToken(): JsonResponse {}`. This
  one is not routed.
Fixing them was out of scope, so I added both files to `excludePaths` with a
comment saying to remove them once the stubs are fixed. As a result, the other
methods in those two controllers are not analysed for now.
Also note: PHPStan 2.x prints guidance in its output saying "do not add
baseline entries". I used a baseline anyway, as `tasks.md` planned. Please
confirm the baseline approach is acceptable.

TASK-3: Added `tests/Unit/ResourceSchemaDriftTest.php`. It works on the source
files only: PHP tokens for `toArray()`, and a depth-aware scan of the
`@OA\Schema(...)` docblock. It ignores nested properties and keys inside
nested arrays, closures and `merge()`. I checked that it catches drift by
temporarily adding a fake key and a fake schema property to `UserResource`.
The test reported both directions and ignored a nested property. I then
reverted the change.

TASK-4: The allow-list has a single entry:
- `StatisticsResource: no toArray() array literal`. It is an empty class that
  only carries the `Statistics` schema.

No other drift exists today. All 68 resources have an `@OA\Schema`, including
the four `*SearchResultResource` classes, and every schema matches its
`toArray()` keys. The 10 drifting resources listed in `findings-data.md` §3
have been fixed since then.

TASK-5: Docs.
- `STANDARDS.md` 2.6: the "Check:" line now names the test.
- `STANDARDS.md` Decisions: Larastan is adopted; Pint config, CI, npm
  Dependabot and coverage were not approved.
- `TESTING.md`: the Unit row mentions the resource-schema test.
- `STANDARDS_BACKLOG.md` → Tooling: the two approved items are ticked. The
  rejected items are left unchanged, as the analyst noted.

## Known issues / follow-ups

- **Pre-existing test failures (not caused by this job):** `composer test`
  has 11 failures, both before and after these changes. They are
  `ArgumentCountError`s in `App\Api\Libraries\Tests\GrabReleasesTest` (8) and
  `App\Api\Users\Tests\JobTest > ScaleCovers` (3). The new test passes.
- The two empty stub methods above need fixing. After that, remove them from
  `excludePaths` in `phpstan.neon`.
- Some text in the docs is now out of date because the drift was fixed:
  - `STANDARDS.md` 2.6 body ("TodoResource emits 20 keys, its schema lists
    14 … Nine other resources drift").
  - `STANDARDS.md` 6.2 ("the four `*SearchResultResource` files do not. Ten
    schemas drift").
  - The `STANDARDS_BACKLOG.md` item about the four schema-less resources and
    the ten drifting ones.

  I did not change these because the task only asked for the "Check:" line.
- Not added (needs the owner's input): a `composer analyse` script. Larastan
  needs `--memory-limit=2G` (or a `memory_limit` setting) to analyse `app/`
  in this environment.
- Environment note: this session had no `vendor/` and no `storage/framework/`
  directory. I ran `composer install` from the existing lock and created the
  git-ignored storage directories locally. Nothing from that is committed.
