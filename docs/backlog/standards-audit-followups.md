# Backlog: smaller standards deviations from the 1.1.0 audit

created: 2026-11-21

## What

Deviations from the audit (`docs/reports/standards-audit-since-1.1.0-2026-11-21.md`)
that the owner chose not to fix now. Fix them when touching the code; none is
urgent. Line numbers as of 2026-11-21.

1. **§10.2 docblocks.** Class-level summaries went from 0 to 10 and prose
   method docblocks from 95 to 174 since 1.1.0 (worst: `PulseMetrics.php`,
   `CalendarService.php`, `FeedReader.php`). `PulseMetrics.php:89-177` and
   `PulseMetricsServiceProvider.php` carry plan labels ("A:"–"F:", "H1:").
   Stop adding new ones; when touching a file, move the useful *why* into `//`
   comments.
2. **§2.4:** `WidgetController.php:297` documents a validation-only 422 with
   `ErrorResponse`; it should be `ValidationErrorResponse`.
3. **§1.1 controllers:**
   - `NoteController.php:46,75,103,131` return what `NoteService` gives back;
     `NoteService.php:217` builds `NoteResource` itself.
   - `UserController.php:160-166` has a super-admin rule and a `match` on the
     service's result.
   - `ExportController.php:35` computes Retry-After in the controller.
4. **§4.4:** `CalendarService.php:361-393` repeats the forget-events plus
   `invalidateSharedCalendar` block in four methods; `WidgetService` repeats
   its timezone/locale pair three times.
5. **§9.3:** `routes/console.php:52-57` schedules a closure with logic.
6. **TESTING.md:** `tests/Feature/PulseMetricsTest.php` breaks "keep
   `tests/Feature` empty"; `ExternalCalendarModelTest.php` and two factory-state
   tests in `DevRequestTest.php` are the model tests TESTING.md says not to
   write; `RecordRateLimitedRequests` has no direct test.
7. **§10.1:** empty `//` stubs in the four `app/Api/Feeds/Exceptions/*` classes.
