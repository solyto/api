# Verdict: connecting external calendars can fail with a 500 halfway through

id: promote
reviewer: @reviewer
date: 2026-11-14

## Review

Reviewed `git diff main...HEAD` on `feature/promote_connecting-external-calendars-can-fail-with-a-500-halfway-through`.
`composer test`: exit 0, no failures (5605 assertions). Every test shows a warning, but the warning only comes from the missing `/workspace/.env` in this container (`tests/TestCase.php:21`). It is environmental and not caused by this job. `composer analyse`: `[OK] No errors`.

TASK-1: PASS
notes: `AppCalendarsPDO::uniqueUri()` takes the slug, adds `-1`, `-2`, … while `(principaluri, uri)` exists, and falls back to `Str::uuid()` when the slug is empty. It runs at the shared creation point, so native calendars get the fix too. There is no schema, connection or route change, and existing uris are untouched. The developer flagged this as the hard rules require.

TASK-2: PASS
notes: The loop runs inside `DB::transaction()` on the default connection. The catch sits outside the transaction, so cleanup runs after the rollback. It removes the DAV calendars created so far through `CalendarService::destroy()`. If the cleanup itself fails, that failure is logged and the original exception is still rethrown. `markExternal` runs only after success. The docblock is updated. `$candidate['url']` is correct, since `listAvailable()` returns a `url` key.

TASK-3: PASS
notes: Three tests cover the cases: "work" next to a local "Work", two emoji-only names, and a failure on the second link. The failure is injected with a throwing `creating` hook, and the test asserts that no links and no new `calendarinstances` remain. Adding `siblings` to `FakeCalDavServer` is a small extension the multi-calendar tests need.

TASK-4: PASS
notes: `CalendarApiTest` posts "Work", "work!", "Work?", "🎉" and "🎈". It expects 201 each time, five distinct non-empty uris, and `work`, `work-1`, `work-2` for the first three.

Out-of-task changes (all justified and documented in implementation.md):
- `DavImportTest` now forces its failure with a test-only SQLite trigger. The old test relied on the slug collision this job removes. The trigger is dropped in `beforeApplicationDestroyed`.
- `docs/backlog/external-calendar-connect-uri-collision.md` is removed, as tasks.md asked.

Not blockers (already listed as follow-ups by the developer): the import's narrow window between select and import, where a same-named calendar can now be created instead of skipped, and the matching slug-only uri issue in `AppContactsPDO.php:52`.

## Security

Not run.

## Overall

APPROVED. No blockers.
