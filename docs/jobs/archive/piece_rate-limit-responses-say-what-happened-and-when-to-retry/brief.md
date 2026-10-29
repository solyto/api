# Brief: Rate-limit responses say what happened and when to retry

type: feature
id: piece
branch: feature/piece_rate-limit-responses-say-what-happened-and-when-to-retry
date: 2026-10-29
author: Leander Muskalla

## What

Give every 429 a machine-readable code and the wait time, so the frontend can
show one translated message ("Too many attempts. Please try again in 3
minutes.") instead of whatever error the page happens to show.

1. Throttle middleware: the handler in `bootstrap/app.php:82-84` builds a fresh
   `ApiResponse::error('Too many requests. Please slow down.', 429)` and drops
   the exception's `Retry-After` header. Return instead:

   ```php
   ApiResponse::error('Too many requests. Please slow down.', 429, ['too_many_requests'],
       meta: ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 0) ?: null])
       ->withHeaders($e->getHeaders());
   ```

   `meta` is the same place the 426 sends `min_client_version`
   (`RecordClientVersion.php:17`). The body is what the frontend reads.
   `Retry-After` is not exposed through CORS (`config/cors.php` →
   `exposed_headers`), so the header only matters to non-browser clients.
2. Export: `ExportController.php:32-36` returns its own 429 for "once every 24
   hours". Add `['export_rate_limited']` and `meta.retry_after` (seconds until
   the last export is 24h old), so it carries the same information.

## Why

The app shows a 429 on login as "login error", on forgot-password as
success, and on the verify page as "could not confirm". Users read the login
case as a wrong password and reset it for no reason. The frontend can't do
better without knowing it was rate-limited and for how long. See
`app/docs/backlog/show-rate-limit-message.md`.

## Steps

1. Change the throttle handler and the export controller as above.
2. Tests:
   - hit `auth-login` 6 times → 429 with `errors: ['too_many_requests']`, an
     integer `meta.retry_after` > 0, and a `Retry-After` header
   - second export within 24h → 429 with `export_rate_limited` and
     `retry_after` close to the remaining time
3. Update the OpenAPI description of 429 if one exists.

## Notes

- Rule 13.2: the status stays 429, and only fields are added. Old builds
  never read the error body.
- Do this before `resend-verification-email.md`, so the new endpoint's 429
  already has this shape.
- Check: `grep -n "too_many_requests" bootstrap/app.php` → 1.
