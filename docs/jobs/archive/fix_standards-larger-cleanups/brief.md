# Brief: Standards: larger cleanups

type: feature
id: fix
branch: feature/fix_standards-larger-cleanups
date: 2026-10-13
author: Leander Muskalla

From `docs/STANDARDS_BACKLOG.md` → "Larger (a feature's worth)". Rule numbers refer to
`docs/STANDARDS.md`; counts are from 2026-10-13. Items marked **(approval)**
need the owner's go-ahead first.

- [ ] `OPENAPI_KNOWN_DRIFT` (80 entries, `tests/Unit/OpenApiSpecTest.php:16-97`):
      fix the annotation paths module by module (`check-in`, `clipboard`,
      `time-tracking/*`, `address-books/*`, `feeds`, `finances/*`,
      `telegram`, `user-settings` → `users/me/settings`, …) until the
      constant is empty, then delete it (rule 2.2).
- [ ] Libraries genre CRUD ×4 (services, controllers, requests, resources):
      one parameterised service/controller pair or leave as is — OQ-4
      first (rule 4.7).
- [ ] Tenant-isolation tests for the 11 API test files that have none
      (`CalendarApiTest`, `CheckInApiTest`, `ContactApiTest`,
      `DevRequestApiTest`, `FeedApiTest`, `LibraryApiTest`,
      `LibraryRestApiTest`, `TimeTrackingApiTest`, `NotificationApiTest`,
      `WeatherTest`, `ExportTest`) — one "other user gets 403 / empty list"
      per resource (`TESTING.md`).
- [ ] Factory/model tests (278 `it(` in 12 files, 39 % of the suite): stop
      adding them; when a file is touched, keep the relation/scope cases and
      drop "creates a valid X" / "has correct fillable attributes"
      (`TESTING.md`). Not a cleanup commit on its own.
