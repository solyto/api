# Implementation: Let unconfirmed users request a new verification email

id: master
developer: @developer
date: 2026-10-29

## Summary

Added the public `POST /api/v1/auth/resend-verification` endpoint. It takes `{ email, platform? }`. If the email belongs to an unverified user and the current token is at least 5 minutes old, the token is replaced (so older links stop working) and the `UserVerification` mail is queued in the user's stored language. The response is always the same generic 200. The new `auth-verification-resend` limiter caps requests at 3/hour per lowercased email and 10/hour per IP.

## Changes

TASK-1: `app/Api/Users/Requests/ResendVerificationRequest.php` (new). Same rules as `ForgotPasswordRequest`.
TASK-2: `app/Api/Users/Services/AuthService.php`, new `resendVerification()`. It returns silently for an unknown user, a verified user, or a token updated within the last 5 minutes. Otherwise it calls `VerificationToken::updateOrCreate` and then `Mail::to()->queue(new UserVerification(...))` with `$user->settings->language ?? 'en'`.
TASK-3: `app/Api/Users/Controllers/AuthController.php`, new `resendVerification()` action with an OpenAPI block (200/422/429). It always returns `success(null, 'If an unverified account exists for this email, a new verification link has been sent.')`.
TASK-4: `app/Shared/Providers/AppServiceProvider.php`, new `auth-verification-resend` limiter with the two limits from the brief.
TASK-5: `routes/api.php`, route added to the public `auth` group. `grep -n "resend-verification" routes/api.php` → 1.
TASK-6: `app/Api/Users/Tests/AuthTest.php`, new `describe('POST /v1/auth/resend-verification')` with the 5 cases (a)–(e). For (c) the test compares the full JSON body (`assertExactJson`) and freezes time, because the envelope contains a timestamp. (e) asserts only the 429 status, since `rate-limit-response-says-when-to-retry` is not merged. No limiter reset was needed: the tests use the `array` cache store, which is fresh per test.
TASK-7: A real queue worker and mailer were **not** available in the container (no Redis, MariaDB or mail server), so the brief's manual check with a real worker was not done. Instead, I ran a throwaway test (not committed) without `Mail::fake()`, using the `sync` queue and the `array` mailer. `SyncQueue` builds the job payload and resolves it through `SyncJob`, so `SendQueuedMailable` with `SerializesModels` is serialized and the models are restored. Result: exactly one mail went to the user, the subject was German (`Bestätige deine E-Mail-Adresse`) for a user with `language = de`, the link contained the user id, the new token and `?platform=mobile`, and posting that id/token to `/auth/verify` returned 201 and set `email_verified_at`.
TASK-8: `composer test` exit 0 (all passing; the warnings are the container's missing `/workspace/.env` and appear on every test). `composer analyse` exit 0 (no errors).

## Known issues / follow-ups

- The real-worker check from the brief's Additions (Redis queue + actual mail delivery) still needs a human on a stack with a worker. The sync-queue run above covers serialization, locale and link, but not Redis transport or mail delivery.
- `.env.testing` shows as modified in the worktree, but git can't hash it ("unsupported file type", it looks like a mount). I left it alone and it is not part of this change.
