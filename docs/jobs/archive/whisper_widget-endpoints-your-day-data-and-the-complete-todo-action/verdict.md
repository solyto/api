# Verdict: widget endpoints: Your Day data and the complete-todo action

id: whisper
reviewer: reviewer
date: 2026-10-29

## Review

Reviewed `git diff main...HEAD`. Every change is either in tasks.md or
explained in implementation.md. `composer test` exits 0: all 1282 tests run
their assertions. Each one shows a warning only because this worktree has no
`/workspace/.env` (phpdotenv `file_get_contents`), which is an environment
issue. `composer analyse` exits 0 with no errors.

TASK-1: PASS
notes: `widgets` limiter, 60/min, keyed `token:<id>` → user id → IP. Existing limiters unchanged.

TASK-2: PASS
notes: `dueTodosInUserTimezone`, `widgetEvents` and `completeTodo` all call `checkAuth()`. `completeTodo` is a no-op when the todo is already completed; otherwise it calls `TodoService::update(['is_completed' => true])`. `dueTodos()` is unchanged.

TASK-3: PASS
notes: en/de/fr/es/it `widgets.php` with matching keys. The de/fr/es/it strings were reused from the frontend's Your Day copy, not passed to the `translator` agent. The developer flagged them for translator review. They read correctly, so this does not block.

TASK-4: PASS
notes: `WidgetService::yourDay` (one service instead of `YourDayService`; documented). Sections come in the order today/due/upcoming, empty ones are dropped, each is capped at 20. Today puts all-day events first, then sorts by start. Events that started earlier and are still running stay in `today`. All-day events and todos are sent as `Y-m-d`; timed events as ISO with the user's offset. Route keys `todo`+id and `calendar`, plus the check action on due rows. `refresh_at` is the next local midnight.

TASK-5: PASS
notes: Language comes from `config('app.locales')` and falls back to `en`. `time_format` must be one of `H:i`/`g:i A`/`g:i a`, else `H:i`. The `date_format` year-less short date is documented in implementation.md.

TASK-6: PASS
notes: `YourDayWidget` schema covers the full format, and `toArray` passes the payload through unchanged.

TASK-7: PASS
notes: `completeTodo` returns 403 through `isResourceOwner()` like `TodoController::update`, 404 through model binding, and `data: null` on success. Annotations cover 200/401/403/404/426/429.

TASK-8: PASS
notes: Both routes are named (`widgets.your-day`, `widgets.actions.todo-complete`) inside the `auth:sanctum` + `TrackApiTraffic` group, with `throttle:widgets`.

TASK-9: PASS
notes: `WidgetApiTest` covers every item of brief step 7: section content and order, tomorrow/completed/+4-day exclusions, the overdue subtitle, Auckland vs Los Angeles, dates vs timestamps, `refresh_at`, language and 12h time, widget and normal tokens, complete (owner/403/404/idempotent/recurring), and widget token 401 on a non-widget route. The out-of-task change to `CalendarService::listWidgetEvents()` (`Carbon::today($tz)` in place of native `DateTime`) is explained, needed for `setTestNow`, and behaves the same (same cache key string).

TASK-10: PASS
notes: Skipped on purpose (optional task), with a sound reason.

TASK-11: PASS
notes: STANDARDS 1.4 now also counts the `widgets.` group and its gate caller.

TASK-12: PASS
notes: Both commands exit 0. I reran them for this review.

## Security

Not run. Ownership check, token-gate names and limiter were reviewed above, and no issues were found.

## Overall

APPROVED

No blockers.
