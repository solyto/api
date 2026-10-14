# Backlog: Client minimum version (426)

created: 2026-10-14

## What
The API can tell outdated builds to update.

- New config value `app.min_client_version`, read from `env('MIN_CLIENT_VERSION')` in `config/app.php`. `null` means the check is off, and that is the default.
- If a request carries `X-Client-Version` lower than the minimum, it gets a 426 through the envelope: `message` "Please update the app.", and `meta.min_client_version`. A request without the header is **allowed**: every build up to 1.0.15 sends none.
- Applies to the `auth:sanctum` group only. `health` and `auth/*` stay reachable, so an outdated app can still connect and log in, and then see the 426.
- It extends the middleware from `client-version-header`; it is not a second middleware. `bootstrap/app.php` already renders an `HttpException(426)` through the envelope.

## Why
This is the safety net for the rare change that can't be made additive (`docs/STANDARDS.md` 13.4). It only reaches builds that send the header and show an update screen on 426. That is why the frontend has to start sending the header now, long before the minimum is ever raised.

## Out of scope
- Raising the minimum. That is an owner decision per release, made from the install counts that `client-version-header` provides.
- Rejecting requests without a header.
- The frontend's update screen (`solyto/app`).

## Notes
Depends on `client-version-header`. The version comparison is numeric per part (`1.0.10 > 1.0.9`); use PHP's `version_compare`.

Tests:
- off (null) → nothing is rejected
- below the minimum → 426 with `meta.min_client_version`
- equal or above → 200
- missing header → 200
- `health` and `auth/login` below the minimum → not rejected

Check: `composer test` and `composer analyse` exit 0.
