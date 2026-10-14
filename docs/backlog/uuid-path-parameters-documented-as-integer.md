# Backlog: UUID path parameters documented as integer

created: 2026-10-14

## What
Every `@OA\Parameter(in="path")` uses the type of the model it binds: `type="string", format="uuid"` for `HasUuids` models, `integer` otherwise (rule 5.4).

## Why
The "Standards: mechanical" job (`f3545db`) fixed Todos only. 98 path params across 29 controllers are still `type="integer"`, and some of them bind UUID models (the job flagged Libraries, Contacts, Calendars, Feeds, Shortcuts), so the published spec and generated clients get the type wrong.

## Out of scope
Changing model key types.

## Notes
Many models really are integer-keyed (`grep -rL HasUuids app/Api/*/Models` lists 28), so check each param against its model rather than replacing in bulk. Calendar/contact ids are DAV instance ids; check them against `app/Dav`, don't assume. `OpenApiSpecTest` doesn't check types.
