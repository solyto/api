# Backlog: Client version header

created: 2026-10-14

## What
Accept and record `X-Client-Version` (the frontend build, e.g. `1.0.16`), which the frontend sends from its next release on (`docs/STANDARDS.md` Decisions).

1. **CORS:** add `X-Client-Version` to `allowed_headers` in `config/cors.php:27`. This must be **deployed before** any frontend build that sends the header, or browsers reject the preflight and every request from that build fails.
2. **Record:** a middleware on the `api` group reads the header. If it is a valid `major.minor.patch`, the version is stored on the authenticated request's token in a new nullable column `personal_access_tokens.client_version`, and only written when it changed. A missing or invalid header is ignored, never rejected.
3. Register the middleware in `bootstrap/app.php` → `withMiddleware`, on the `api` group only. It must not run on `routes/web.php` (DAV, Telegram webhook).

## Why
Rule 13.4 says a field may only be removed once no supported build reads it. Today the API can't tell which builds are still in use. Each token is one install (`auth-token-{platform}`, `last_used_at`), so with `client_version` on the token, "active installs per version and platform" is one query. The minimum-version check (`client-minimum-version`) also builds on this header.

## Decisions
- Stored on the token, not in Pulse or the logs. It persists, and it counts installs, not requests.
- No `exposed_headers`. Anything the client must read goes in the JSON body (`STANDARDS.md` 13.2).

## Out of scope
- Rejecting old clients (`client-minimum-version`).
- The frontend change (`solyto/app`: send the header on every request, including the `fetch` in `src/routes/setup/+page.svelte`).
- DAV.

## Notes
This is the first custom middleware in `app/` (none exists today), so it is a new kind of class (hard rule: no new abstractions without the owner). The owner approved it on 2026-10-14. Put it under `app/Shared/`.

The migration is additive (one nullable column) and must be shown before it is run (hard rule).

Tests: a header on an authenticated request sets `client_version`; a missing or garbage header changes nothing and the request succeeds; a preflight with the header is allowed.

Check: `composer test` and `composer analyse` exit 0.
