# Backlog: Pulse card fixes

created: 2026-11-18

## What

Smaller problems in the custom Pulse cards added after 1.1.3
(`app/Shared/Pulse/**`, `resources/views/pulse/metric-card.blade.php`).

1. **Rows disappear or show 0 above 101 keys.** `MetricCard` queries each
   column separately, and each query returns only its own top 101 keys. The
   first column decides which rows exist; the others fill in 0. With more than
   101 feeds the Feed Sync card shows an arbitrary 101 (every feed syncs hourly,
   so the run counts tie), and a feed that always fails can be missing or show
   "Failed 0". Sorting only sorts the rows already picked. External Calendars
   could hit this with more than 101 hosts.
2. **Zero shows as "<1 ms".** `number_format(0)` is `"0"`, which is falsy, so
   `?: '<1'` kicks in. Image-lookup time is usually 0.
3. **Release Lookups card text** says "per user and library"; the key is
   `library · source`.
4. **Remote CalDAV failures are classified loosely** (`RemoteCalDavClient.php`
   ~381-389): only `ExternalCalendarUnavailableException` records a failure;
   other exceptions count as successes, while expected 404/412 count as
   failures.
5. **426 keys come from an unauthenticated header.** `RecordClientVersion`
   runs before auth and keys by `X-Client-Version`, so any client can add new
   keys to the Client Versions card.
6. The `pulse-queue-size` schedule (`routes/console.php`) calls
   `Queue::size()` outside `Pulse::rescue`, so a Redis outage fails that task
   (only that task).

## Suggestion

1. Pick the rows from the column that matters (e.g. failures first, then
   slowest), or query all columns for one key set.
2. Compare against `null` instead of using `?:`.
3. Fix the text.
4. Record any exception as a failure; don't count expected 404/412.
5. Record only versions that parse and fall in a sane range, or collapse
   unknown ones into "Other".
6. Move the size call inside the rescue.
