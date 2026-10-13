# Verdict: Standards: tooling (approval needed)

id: love
reviewer: @reviewer
date: 2026-10-13

## Review

Branch `feature/love_standards-tooling-approval-needed` was checked. It matches
`brief.md`. The review was done against `git diff main...HEAD` (base `main` from
`.manigot/manigot.json`).

Scope: none of the rejected items were touched. There is no `pint.json`, no
`.github/` change, no Pint run and no pcov. The only changes outside the job
directory are `composer.json`, `composer.lock`, `phpstan.neon`,
`phpstan-baseline.neon`, `tests/Unit/ResourceSchemaDriftTest.php` and the three
docs. All of them are covered by tasks.md and implementation.md. (The
working-tree `M .env.testing` is the sandbox's `/dev/null` device mount. It is
not a change and is not committed.)

TASK-1: PASS
notes: `composer.json` gains one line (`larastan/larastan: ^3.13`). The
`composer.lock` diff only adds `larastan/larastan` v3.13.0, `phpstan/phpstan`
2.3.1 and `iamcal/sql-parser` v0.7. No other package changed version.

TASK-2: PASS
notes: `phpstan.neon` has the Larastan extension, the baseline, `level: 5`,
`paths: [app]` and `excludePaths: [app/Dav]`. I ran
`./vendor/bin/phpstan analyse --memory-limit=2G` and got `[OK] No errors`.
The two extra excluded files are a documented and justified deviation:
`NotesImportExportController::export()` (line 104) and
`TelegramBotConnectionController::confirmToken()` (line 189) are indeed empty
`JsonResponse {}` stubs, and PHPStan reports them as non-ignorable
`return.missing`. A comment in `phpstan.neon` says to remove the exclusions
later. The baseline (1011 entries) holds only type, property and method names.
I found no secret values in it. Using a baseline was the analyst's flagged
assumption. The developer raised it again for the owner, so it is not a
blocker.

TASK-3: PASS
notes: `tests/Unit/ResourceSchemaDriftTest.php` parses the `@OA\Schema`
properties at depth 1 and the top-level `toArray()` keys from tokens. It
follows the `OPENAPI_KNOWN_DRIFT` allow-list pattern and reports both new and
fixed drift. No resource or annotation was changed. I ran the test's own
parsing logic separately over all 68 resources. Every resource gives non-zero,
matching counts on both sides (for example, TodoResource 21/21 and
NotificationSettingsResource 36/36), so the check isn't vacuous.

TASK-4: PASS
notes: The allow-list holds only `StatisticsResource: no toArray() array literal`.
I confirmed that `StatisticsResource` is an empty class with an
`@OA\AdditionalProperties` schema. The test passes. Its only warning is a
missing `.env` in this sandbox, which is an environment issue, not a code
issue. The four `*SearchResultResource` classes now have schemas and are
checked (6–7 properties each).

TASK-5: PASS
notes: `STANDARDS.md` 2.6 now has a "Check:" line, and its Decisions entry
was updated. The `TESTING.md` Unit row and the two ticks in
`STANDARDS_BACKLOG.md` match the task. The rejected backlog items were left
alone, as planned. The out-of-date drift prose in 2.6 and 6.2 is noted in
implementation.md as a follow-up. That is correct, because it was outside the
task.

## Security

None found. No secrets appear in the baseline, config or test. `.env.testing`
is not part of the diff.

## Overall

APPROVED

Nothing blocks the merge. These items are for the owner to decide, not
blockers: whether the PHPStan baseline is acceptable, a `composer analyse`
script, fixing the two empty controller stubs, and updating the out-of-date
drift prose.
