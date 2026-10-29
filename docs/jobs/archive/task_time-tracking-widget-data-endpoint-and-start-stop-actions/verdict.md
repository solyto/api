# Verdict: time tracking widget: data endpoint and start/stop actions

id: task
reviewer: reviewer
date: 2026-10-29

## Review

Diff reviewed: `git diff main...HEAD` (base `main`). Every change matches `implementation.md`; nothing changed outside the tasks. The app's own `/time-tracking/*` endpoints and `TimeTrackingService` are untouched. No migration, no dependency, no DAV/`pgsql` change.

Verification (run by the reviewer in the worktree):
- `composer test`: exit 0 (full suite, 0 failures). Every test shows a warning because `/workspace/.env` is missing in the worktree (`file_get_contents(/workspace/.env)` in phpdotenv). That comes from the environment and has nothing to do with this change.
- `composer analyse`: `[OK] No errors`.

TASK-1: PASS
notes: `resources/lang/{en,de,fr,es,it}/widgets.php` have the four keys. The developer wrote de/fr/es/it themselves instead of handing them to the `translator` agent, because no translator sub-agent was available (disclosed in implementation.md). The strings use the I18N.md glossary term for Time Tracking (Zeiterfassung / suivi du temps / control de tiempo / monitoraggio del tempo) and are short and correct. This is a process deviation, not a defect.

TASK-2: PASS
notes: `IntegrationGateway.php`: `checkAuth()` on all four methods, `TimeTrackingService` resolved late via `app()` (STANDARDS 12.8). In `startTimeTracking`, one `DB::transaction` (3 attempts) runs `lockForUpdate()` on the user's running entries, then stops the others via `stopTimer` and starts via `startTimer` only if the project isn't already running. When the user is idle there are no rows to lock. On InnoDB REPEATABLE READ the indexed `user_id` (FK) gap lock still serializes the inserts, and the resulting deadlock is retried. The ordering relies on NULL sorting last in DESC, which is true for MariaDB and SQLite.

TASK-3: PASS
notes: `WidgetService::timeTracking` matches the brief. There is no `refresh_at`. Empty sections are dropped. `subtitle`/`color` are omitted via `withoutNulls`, and the color is checked against `#RRGGBB`. `at` is in the user's timezone with an offset. The running project is excluded in the query, so the cap of 20 is applied after excluding it.

TASK-4: PASS
notes: New `TimeTrackingWidget` schema documents live, color, the button action with its icon, and the new keys. In `YourDayResource`, only the `refresh_at` description changed (allowed by tasks.md).

TASK-5: PASS
notes: Three routes in the `widgets.` group with the specified names. Placeholders `{project}`/`{entry}` match the action parameters. Ownership is checked with `abort_unless(isResourceOwner, 403)` and the response is a success envelope with `null` data. OpenAPI covers 200/401/403/404/426/429.

TASK-6: PASS
notes: Every test listed in brief step 5 is present and passes. That covers the payload shape, ordering, cap, running exclusion, invalid colors, German labels, widget and normal tokens, start (idle / switch with 45-minute duration / no-op / alternating taps / 403 / 404), stop (stops / no-op / 403 / 404), and the widget token getting 401 on `/time-tracking/*`.

TASK-7: PASS
notes: Both gates exit 0 (see Verification above).

## Security

Not run separately. Ownership is enforced on both actions before any write. The routes inherit the widget-token gate and the `widgets` limiter. The widget token still gets 401 on `/time-tracking/*` (tested).

## Overall

APPROVED

No blockers.
