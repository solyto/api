# Backlog: Dev-request attribution from the authenticated user

created: 2026-10-14

## What
A dev request's author comes from the authenticated user, not from the request body. `StoreDevRequestRequest.php:19` accepts a client-chosen `created_by_user_id` (bare `exists:users,id`) and `DevRequestService::create:27` stores it as-is.

## Why
Any user can file a dev request in another user's name, and that user then receives the comment notifications (`DevRequestService.php:93`). Rule 3.2 says attribution comes from `$request->user()`.

## Out of scope
Other `exists:` rules; the remaining six are compliant (re-scoped or intentionally cross-user).

## Notes
Blocked on OQ-8 in `docs/STANDARDS.md` → Open questions: is filing on behalf of someone else intended (e.g. admins)? Answer it first; if not intended, drop the field from the rule and set it in the service. Deferred from the "Standards: first" job (`d48f7b9`).
