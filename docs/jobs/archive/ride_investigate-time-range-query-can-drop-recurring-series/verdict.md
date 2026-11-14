# Verdict: investigate — time-range query can drop recurring series

id: ride
reviewer: reviewer
date: 2026-11-14

## Review

Reviewed `git diff main...HEAD` (base `main`, from `.manigot/manigot.json`)
on `feature/ride_investigate-time-range-query-can-drop-recurring-series`.
Every changed file is either listed in tasks.md or is a job doc. The brief
is a rename of the closed backlog entry.

Verification run in this worktree:
- `composer test`: exit 0, 5474 assertions, no failures. Every test in the
  suite shows a warning because `/workspace/.env` is missing in the sandbox
  (phpdotenv `file_get_contents`, from `tests/TestCase.php:21`). That comes
  from the environment, not from this job. I created the untracked
  `storage/app/public/user` and `storage/framework/*` dirs first, as
  implementation.md says to.
- `composer analyse`: exit 0, no errors.
- The job's own tests (`Recurring series with an override` ×2,
  `Series past sabre's 2038 cap`, `app:calendars:recompute-event-bounds` ×2)
  all pass.

TASK-1: PASS
notes: `app/Dav/Tests/EventTimeRangeQueryTest.php` runs the override-first
and master-first cases as a dataset. It checks the stored
`firstoccurence`/`lastoccurence` (master DTSTART and `2145916800`), that the
ETag equals the md5 of the PUT body, `getEventObjectsInRange`, and
`listExpanded` for the 2030-10-14..17 window. implementation.md records the
failure from before the fix (`1894618800 is identical to 1894010400`) and the
stored values, so the reproduction the brief asked for is there.

TASK-2: PASS
notes: `AppCalendarsPDO::getDenormalizedData()` still gets etag, size, uid
and componentType from `parent::` on the original string. Only the two
occurrence keys are replaced, and only when the first non-VTIMEZONE
component is a VEVENT with a RECURRENCE-ID and a master with the same UID
exists. Master-first objects, objects with only overrides, and
VTODO/VJOURNAL return null, so they are unchanged. The bounds are not
widened with min/max over the overrides, as the tasks asked (sabre#1512 is
out of scope). `$vCal` is destroyed in `finally`. The docblock explains why
the override exists. Invalid data already throws in `parent::` before the
second parse.

TASK-3: PASS
notes: `RecomputeEventOccurrenceBounds` is a dry run unless `--apply` is
given. It chunks VEVENT rows on `pgsql` by id, handles bytea returned as a
resource, skips rows without `RECURRENCE-ID` before parsing, and reports
unparseable rows and leaves them as they are. It updates only
`firstoccurence`/`lastoccurence`. It is registered in `bootstrap/app.php`.
The test inserts stale bounds directly and checks two things: the dry run
changes nothing, and `--apply` fixes only the stale row while etag, size,
lastmodified and the `calendarchanges` count stay the same. The ⚑ pgsql data
write and the production run (dry run first, then `--apply`) are flagged in
implementation.md.

TASK-4: PASS
notes: The clause is now `(lastoccurence > ? OR lastoccurence >= ?)`, with
`LAST_OCCURRENCE_CAP = 2145916800` as a commented private const. The test
covers a 2039 window: the infinite series and the series ending in 2040 are
listed, and the one ending in 2031 is not. A finite series that ends just
past the cap can still be returned by `getEventObjectsInRange` for later
windows. That is the accepted trade-off in tasks.md, and the occurrence loop
in `listExpanded` filters it out.

TASK-5: PASS
notes: The backlog entry was removed (it moved to brief.md through the job
scaffold). implementation.md covers everything TASK-5 lists: the failing
then passing output, the ⚑ DAV REPORT behaviour change, the backfill run
procedure, that real-client ordering was not checked live, and sabre#1512 as
an open limitation.

## Security

Not run. No new routes, no user input beyond the DAV body that sabre already
parses, and the command is CLI-only.

## Overall

APPROVED

No blockers. Before deploy, the maintainer should note the two ⚑ items from
implementation.md:
- sabre's calendar-query REPORT now returns override-first series.
- The `app:calendars:recompute-event-bounds` backfill writes `pgsql` rows.
  Run it as a dry run first.
