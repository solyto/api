# Brief: time tracking improvements

type: feature
id: symbol
branch: feature/symbol_time-tracking-improvements
date: 2026-10-10
author: Leander Muskalla

## What

The frontend time-tracking improvement job (see `brief.md`) needs a handful of
small API changes and contract clarifications to close gaps that force the client
to duplicate or second-guess the model. Requested changes:

- **Editable `has_exact_times`.** Add optional `has_exact_times?: boolean` to
  `UpdateTimeTrackingEntryRequest` and persist it, so editing an entry's exact
  times can set/clear the flag.
- **A project colour.** Give `TimeTrackingProject` its own `color`
  (plus optional `color` on create/update), or designate an existing field as the
  single project-colour authority — so the UI stops colouring a project by its
  first category while the statistics endpoint returns a different colour.
- **Authoritative `total_duration`.** Confirm/guarantee that
  `TimeTrackingProject.total_duration` is maintained on entry create/update/delete
  and timer stop, so the client can consume it instead of recomputing totals.
- **Duration-only entry contract.** Define what is authoritative when
  `has_exact_times` is false and where a backdated duration-only entry anchors
  (server-owned anchoring, or a documented rule) so the UI can place it on the
  correct day.
- **Project-less entries.** `project_id` is required on create, but `project` is
  nullable on read. State whether entries are cascade-deleted with their project
  (the frontend then drops its "uncategorized" fallback) or intentionally
  orphaned to `null`.
- **Single active timer.** Confirm the start endpoint enforces one active timer.
- **Statistics completeness (optional).** Let the statistics endpoint accept a
  category filter, guarantee its per-project `color`/`total_minutes` match the
  project resource, and document the date range's inclusivity and timezone.

## Why

These gaps make the frontend duplicate the model (recompute totals, infer
colours), mishandle timezones and flags, or code defensively for states the
contract forbids. Closing them lets the improvement job make the API the single
source of truth.

## Out of scope

- Frontend changes — tracked in `brief.md`.
- New analytics/reporting product features beyond what the dashboard shows today.

## Notes

- The frontend is only a consumer; `src/lib/types/time_tracking.ts` and
  `src/lib/config/apiRoutes.ts` mirror this API.
- The `has_exact_times` and project-colour changes are blocking for the unified
  entry editor and the project-colour work; the rest are contract clarifications
  that may ship as documentation if no code change is required.
- Keep changes backward-compatible (all new fields optional) so the current
  frontend keeps working during rollout
