# Backlog: Tell the client when login fails only because the email is unconfirmed

created: 2026-10-29

## What

When the password is correct but `email_verified_at` is null (and
`auth.policy.confirmation` is on), keep returning 401 but add the error code
`email_unverified`, the same way `verify` does with `['token_expired']`:

```php
return ApiResponse::error('Please verify your email address first.', 401, ['email_unverified']);
```

Today `LoginRequest::authenticate` (`LoginRequest.php:31`) throws a
`ValidationException`, and `AuthController::login` (`:106-113`) turns every
failure into a bare `ApiResponse::unauthorized()`. Give the unverified case its
own exception (or code) so the controller can tell it apart from wrong
credentials.

## Why

An unconfirmed user who logs in only sees "login error" and keeps retrying
their password. With the code, the frontend can say what's wrong and offer to
resend the mail (`resend-verification-email.md`).

This doesn't help anyone enumerate accounts. The code is only returned after
`Auth::attempt` succeeded, so the caller already knows the email and the
password.

## Steps

1. Split the unverified case out in `LoginRequest::authenticate`, and map it
   in `AuthController::login` to the 401 above.
2. Tests in `AuthTest.php`:
   - unverified user with the correct password → 401 with `email_unverified`
   - unverified user with a wrong password → plain 401 without the code
   - confirmation off → login succeeds as today

## Notes

- Rule 13.2: the status stays 401 and only the error body changes. Old builds
  never read the error body, so they keep showing their generic message.
- Depends on nothing, but only useful together with
  `resend-verification-email.md` and the app entry
  `app/docs/backlog/login-offers-resend-when-unverified.md`.
- Check: `grep -rn "email_unverified" app/Api/Users` → at least 1.
