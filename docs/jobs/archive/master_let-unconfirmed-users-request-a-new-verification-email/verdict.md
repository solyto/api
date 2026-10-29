# Verdict: Let unconfirmed users request a new verification email

id: master
reviewer: @reviewer
date: 2026-10-29

## Review

Branch `feature/master_let-unconfirmed-users-request-a-new-verification-email` was checked. The diff against `main` contains only the files listed in implementation.md and the job docs. Nothing is out of scope.

TASK-1: PASS
notes: `ResendVerificationRequest` has exactly the same rules as `ForgotPasswordRequest`.

TASK-2: PASS
notes: `AuthService::resendVerification` returns silently for an unknown user, a verified user, or a token less than 5 minutes old. It replaces the token with `updateOrCreate` on the unique `user_id` and queues the `UserVerification` mail in `$user->settings->language ?? 'en'` (the `??` also covers a missing settings row). The check-then-update is not atomic, but the per-email limiter bounds that, so it doesn't matter.

TASK-3: PASS
notes: The action always returns the same `ApiResponse::success(null, …)`. The OpenAPI block documents 200, 422 and 429.

TASK-4: PASS
notes: The limiter matches the brief exactly: 3 per hour per lowercased email and 10 per hour per IP.

TASK-5: PASS
notes: The route is in the public auth group. `grep -n "resend-verification" routes/api.php` returns 1 line.

TASK-6: PASS
notes: Cases (a)–(e) are all present. (a) also checks that the old token gets `token_mismatch`. (c) uses `assertExactJson` with time frozen, so the message can't differ by account state. (e) mixes casing and asserts 429 only, which is correct because `rate-limit-response-says-when-to-retry` is not merged. All 5 tests pass.

TASK-7: PARTIAL (not a blocker)
notes: There was no real worker or mailer in the container, and the developer said so instead of skipping the check. As a substitute, they ran the mail through `sync` queue with the `array` mailer, without `Mail::fake()`. That path serializes the `SendQueuedMailable` payload the same way Redis does. The result: German subject, a correct link with `platform`, and the link verified. Still open for a human: one end-to-end run on a stack with a Redis worker to confirm transport and delivery.

TASK-8: PASS
notes: I re-ran both checks myself. `composer test` exited 0 (1242 tests; the warnings are the container's missing `.env`, which affects every test). `composer analyse` exited 0 with no errors.

## Security

Not run separately. The response body and status are the same whether or not the account exists, and the mail is queued, so response time doesn't reveal it either. Both rate limits are in place. Tokens are still stored in plain text, which the brief puts out of scope.

## Overall

APPROVED

No blockers.
