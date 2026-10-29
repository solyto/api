# Backlog: Let unconfirmed users request a new verification email

created: 2026-10-29

## What

Add `POST /auth/resend-verification` with `{ email, platform? }`. If an
unverified user with that email exists, replace their verification token and
send the `UserVerification` mail again. The response is always the same
generic 200, whether or not the account exists or is already verified.

Model it on `forgotPassword` (`AuthController::forgotPassword`,
`PasswordService::requestReset`):

- New `ResendVerificationRequest` (`email` required, `platform` optional,
  same `in:web,mobile,desktop` rule as `RegisterRequest`).
- New `AuthService::resendVerification(string $email, ?string $platform)`:
  return silently if there is no user or `email_verified_at` is set. Otherwise
  `VerificationToken::updateOrCreate(['user_id' => …], ['token' =>
  Str::random(40), 'expires_at' => now()->addHours(24)])` and send the mail
  with the user's stored language (`$user->settings->language ?? 'en'`).
  `verification_tokens.user_id` is unique, so this replaces the old token and
  older links stop working.
- Cooldown per user: if the existing token's `updated_at` is under 5 minutes
  old, return without sending. The response stays the same.
- Queue the mail (`Mail::to(...)->queue(...)`), so the response time doesn't
  show whether the account exists. Both `deployment/templates/api/compose.yml.j2`
  and `selfhosted/compose.yml` run a worker.
- New limiter `auth-verification-resend` in `AppServiceProvider`, with two limits:

  ```php
  return [
      Limit::perHour(3)->by('resend:email:' . Str::lower((string) $request->email)),
      Limit::perHour(10)->by('resend:ip:' . $request->ip()),
  ];
  ```

- Route next to the other guest auth routes (`routes/api.php:79-86`), with
  an OpenAPI block like its neighbours.

## Why

Today an account whose link expired (24h, `AuthService::register`) or whose
mail got lost is locked for good. Login rejects it (`LoginRequest.php:31`),
nothing creates a new token, the email is `unique:users` so the user can't
register again, and a password reset doesn't set `email_verified_at`. The
only way out is the admin CLI or the database.

## Steps

1. Request, service method, controller action, limiter, route, OpenAPI.
2. Tests in `app/Api/Users/Tests/AuthTest.php` with `Mail::fake()`:
   - an unverified user gets a new token and one queued mail, and the old
     token no longer verifies
   - the new token verifies through `POST /auth/verify`
   - unknown and already-verified emails get the identical response and no mail
   - a second request inside the cooldown sends no mail and keeps the token
   - the 4th request for the same email within an hour returns 429
     (casing doesn't matter for the email key)

## Out of scope

- Hashing verification tokens at rest. They are stored in plain text today,
  the same as before this change.
- Deleting unverified accounts after some time.
- Making `forgotPassword` queue its mail too. It has the same timing leak,
  but that's a separate change.

## Notes

- Additive only (rule 13.2): `register`, `verify` and `login` are unchanged,
  and old builds never call the new route.
- With `EMAIL_CONFIRMATION=false`, nobody is locked out, but the endpoint
  still works and is harmless.
- The web frontend counterpart: `app/docs/backlog/resend-verification-email.md`.
- Check: `grep -n "resend-verification" routes/api.php` → 1.
