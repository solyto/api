## Summary

Time tracking widget for Android: `GET /widgets/time-tracking` (running timer + projects to start, in the generic widget format) and the `time_tracking.start/{project}` / `time_tracking.stop/{entry}` actions, all in the `widgets.*` group, so the widget token and the `widgets` limiter apply unchanged. Cross-module access goes through `IntegrationGateway` (STANDARDS 12.8). `composer test` and `composer analyse` both exit 0.

## Changes

TASK-1: `resources/lang/{en,de,fr,es,it}/widgets.php`: `time_tracking_title`, `time_tracking_empty`, `section_running`, `section_projects`. No translator sub-agent was available in this session, so I followed `docs/agents/translator.md` / `docs/I18N.md` myself (glossary term for Time Tracking, informal voice). The key check prints nothing. Owner review: en "Time Tracking / No projects yet. / Running / Projects"; de "Zeiterfassung / Noch keine Projekte. / Läuft / Projekte"; fr "Suivi du temps / Pas encore de projets. / En cours / Projets"; es "Control de tiempo / Aún no hay proyectos. / En curso / Proyectos"; it "Monitoraggio del tempo / Ancora nessun progetto. / In corso / Progetti".
TASK-2: `app/Shared/Services/IntegrationGateway.php`: `runningTimeTrackingEntry()`, `timeTrackingProjectsByLastUse($limit, $exceptProjectId)` (`withMax('entries', 'started_at')`, desc, then title), `startTimeTracking($project)` (one `DB::transaction` with 3 attempts and `lockForUpdate()` on the user's running entries: stops the other running entries via `stopTimer`, starts the project via `startTimer` unless it is already running), `stopTimeTracking($entry)` (no-op when already stopped).
TASK-3: `app/Api/Widgets/Services/WidgetService.php`: `timeTracking(User)` builds the payload (no `refresh_at`, `subtitle` and `color` only when set/valid `#RRGGBB`, `at` in the user's timezone, the running project left out of `projects` in the query, so the cap of 20 applies after excluding it), plus thin `startTimeTracking` / `stopTimeTracking` wrappers.
TASK-4: new `app/Api/Widgets/Resources/TimeTrackingWidgetResource.php` with the `TimeTrackingWidget` schema (live, color, button action with icon, new keys); `YourDayResource` schema now says `refresh_at` is optional.
TASK-5: `WidgetController` actions `timeTracking`, `startTimeTracking`, `stopTimeTracking` with OpenAPI annotations (401/403/404/426/429); routes `widgets.time-tracking`, `widgets.actions.time-tracking-start`, `widgets.actions.time-tracking-stop` in `routes/api.php`.
TASK-6: `app/Api/Widgets/Tests/WidgetApiTest.php`: payload (shape, running-only-while-running, no subtitle, empty, last-use order + cap + running excluded, invalid colors, German), start (idle, switch with duration, no-op, alternating taps never leave two running, 403, 404), stop (stops, no-op, 403, 404), widget/normal token, widget token gets 401 on `/time-tracking/*`.
TASK-7: full suite and PHPStan run, both exit 0.

## Known issues / follow-ups

- Ordering relies on MariaDB and SQLite both sorting NULL last in `DESC` (commented in the gateway). This was only verified on SQLite here.
- The concurrency guarantee comes from `lockForUpdate` + deadlock retry on MariaDB. SQLite ignores the lock, so the tests only cover it sequentially.
- To run tests, the worktree needed `composer install` and `storage/framework/*` dirs (both gitignored). The first full run had 2 failures in `ExportTest` (unrelated, didn't reproduce on two reruns or in isolation), so that test may be flaky.
- `.env.testing` shows as modified but git cannot hash it (a mount), so it was left alone.
