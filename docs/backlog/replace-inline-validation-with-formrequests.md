# Backlog: Replace inline validation with FormRequests

created: 2026-10-14

## What
Move all 20 inline `$request->validate([...])` calls in `app/Api/*/Controllers` into FormRequests (rule 3.1: no inline validation, owner decision 2026-10-14). Check: `grep -rn '\$request->validate(' app/Api/*/Controllers` → 0.

## Why
The rule used to allow up to three fields on child/action endpoints; it now allows none. The sites: `EventAttachmentController:136,232`, `CalendarController:160`, `FeedController:206,278`, `Library{Book,Movie,Game,Recipe,Music}Controller` `import` (5), `CheckInController:75`, `NoteController:106`, `ShortcutController:220`, `TimeTrackingEntryController:260,384`, `TodoController:320,383`, `TodoWorkspaceController:272,334`, `PasskeyController:91`. (`NotificationSettingsController::update`, 36 fields, is done: `UpdateNotificationSettingsRequest`.)

## Out of scope
Changing the rules themselves, and the bare `exists:` rules they contain (`TimeTrackingEntryController:261,387` are re-scoped or user-filtered and stay).

## Notes
Identical shapes can share one request: the five Libraries `import` `url` rules, `CheckInController:75` / `NoteController:106` (same date range), and `TodoWorkspaceController` attach/detach. Validation-error responses must stay the same 422. Can be split per module.
