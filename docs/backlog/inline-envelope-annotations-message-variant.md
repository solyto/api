# Backlog: Inline-envelope annotations, message variant

created: 2026-10-14

## What
46 `@OA\Property(property="message", ...)` lines in 12 controllers still redefine the success envelope (`data` + `message` without `allOf SuccessResponse`). Convert them to the rule 2.3 shape, and extend rule 2.3's Check to `property="message"` so it catches this variant.

## Why
Rule 2.3's Check only greps `property="success"`, so this second variant passes it (code-quality report §3.12). Largest files: `TimeTrackingEntryController` (7), `DevRequestController` (6), `ShortcutController`, `TagController`, `TimeTrackingProjectController` (5 each).

## Out of scope
Changing the responses themselves; this is annotations only.

## Notes
Mechanical. `OpenApiSpecTest` and `composer analyse` must stay green; regenerate the spec and diff it to confirm only the envelope shape changed.
