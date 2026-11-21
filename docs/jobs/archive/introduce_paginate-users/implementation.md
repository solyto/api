## Summary

`GET /api/v1/users` (the admin user list) is now properly paginated and searchable, and the new `GET /api/v1/users/growth` feeds the signup chart. It accepts
`page` (>= 1) and `per_page` (1–100), returns `meta.pagination` in the standard
envelope, and orders newest first with a stable tie-breaker. If `per_page` is
omitted it keeps the old page size of 1000, so released frontends see no change
(STANDARDS 13.1/13.2). The contract was posted as a decision in the room
`paginate-users`.

## Changes

TASK-1:
- `app/Api/Users/Requests/ListUsersRequest.php` (new): validates `page` and `per_page` (rule 3.1).
- `app/Api/Users/Controllers/UserController.php`: `index` takes the request and passes `per_page` (default `LEGACY_PAGE_SIZE` = 1000) to the service. The OpenAPI annotation now documents `page`/`per_page`, `meta.pagination` (`PaginationMeta`) and the 422 response.
- `app/Api/Users/Services/UserService.php`: `list` adds `orderByDesc('id')` after `latest()`, so users with the same `created_at` don't shift between pages.
- `app/Api/ApiResponse.php`: a `ResourceCollection` that wraps a paginator now gets `meta.pagination`. Before this, only a bare paginator got it, so this endpoint never returned the meta. No other endpoint passes a paginated collection, so this is the only response that changes.
- `app/Api/Users/Tests/UserApiTest.php`: covers page contents/order, the meta values, the legacy default and 422 on invalid params.

TASK-2 (the app side needed this to keep the admin page's existing search and growth chart):
- `GET /api/v1/users?search=`: optional, max 255 characters. It's a case-insensitive substring match on name OR email, applied before paging. `%`, `_` and `!` are matched literally: the LIKE uses `escape '!'` via `whereRaw` (rule 5.5), because SQLite and MariaDB treat backslash escapes differently.
- `GET /api/v1/users/growth?group_by=year|month|week|day` (new, admin-only via `UserPolicy::viewAny`): returns `data [{period, total}]`, the cumulative user count at the end of each UTC period, ascending. year/month only list periods that had signups. week covers the last 52 ISO weeks (`2026-W03`) and day the last 30 days, both gap-filled and starting from the earlier total. It's computed in PHP from `created_at` only, which avoids SQL date functions that differ between MariaDB and SQLite. `group_by` is validated with `Rule::enum(UserGrowthPeriod::class)`, and the enum is new.
- `routes/api.php`: `users/growth` is registered in the `users` prefix group.
- Tests cover search (case, name/email, literal wildcards), a page beyond the last, growth for every grouping (with frozen time), 422 and 403.

`composer test` (1863 passed) and `composer analyse` (no errors) both exit 0.

## Known issues / follow-ups

- `/users/growth` loads every `created_at` value (one column). That's fine at thousands of users, but at much larger scale it should move to a SQL `GROUP BY`.
- Once no supported frontend build calls `/users` without `per_page`, the default page size of 1000 can be lowered (STANDARDS 13.4).
