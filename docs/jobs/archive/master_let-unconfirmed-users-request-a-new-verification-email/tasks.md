# Tasks: Let unconfirmed users request a new verification email

id: master
analyst: @analyst
date: 2026-10-29

<!-- Produced by @analyst from brief.md. -->

Branch checked: worktree HEAD is `feature/master_let-unconfirmed-users-request-a-new-verification-email`, which matches the brief.

## Task breakdown

TASK-1: Add `ResendVerificationRequest`. `email` uses the same rule as `ForgotPasswordRequest` (`required|string|email|max:255`), and `platform` is `sometimes|nullable|string|in:web,mobile,desktop`.
  files: app/Api/Users/Requests/ResendVerificationRequest.php (new)
  depends: none
  risk: low. It is a plain FormRequest copied from `ForgotPasswordRequest`.

TASK-2: Add `AuthService::resendVerification(string $email, ?string $platform): void`. It looks up the user by email and returns silently if there is no user, if `email_verified_at` is set, or if the existing `VerificationToken` was updated less than 5 minutes ago. Otherwise it calls `VerificationToken::updateOrCreate(['user_id' => $user->id], ['token' => Str::random(40), 'expires_at' => now()->addHours(24)])` and runs `Mail::to($user->email)->queue(new UserVerification($user, $token, $platform ?? 'web', $user->settings->language ?? 'en'))`.
  files: app/Api/Users/Services/AuthService.php
  depends: none
  risk: medium. This is the first time `UserVerification` is queued. Its properties are `private readonly`, it uses `SerializesModels`, and the token model is re-fetched on the worker. `Mail::fake()` doesn't exercise any of that (see TASK-7).

TASK-3: Add `AuthController::resendVerification(ResendVerificationRequest $request)`. It calls the service and always returns `ApiResponse::success(null, <generic message>)` with status 200. Add an OpenAPI block in the same style as `confirmAccountDeletion`: request body with `email` and `platform`, plus 200, 422 and 429 responses.
  files: app/Api/Users/Controllers/AuthController.php
  depends: TASK-1, TASK-2
  risk: low. The action is a thin wrapper, like `forgotPassword`.

TASK-4: Register the rate limiter `auth-verification-resend` in `AppServiceProvider`. It returns the two limits from the brief: 3 per hour keyed on the lowercased email, and 10 per hour keyed on the IP.
  files: app/Shared/Providers/AppServiceProvider.php
  depends: none
  risk: low. It is additive and sits next to the existing limiters.

TASK-5: Add `Route::middleware('throttle:auth-verification-resend')->post('resend-verification', [AuthController::class, 'resendVerification']);` to the public `auth` group (`routes/api.php:78-87`). Check: `grep -n "resend-verification" routes/api.php` should return exactly 1 line.
  files: routes/api.php
  depends: TASK-3, TASK-4
  risk: low. It is a new public route and no existing routes change.

TASK-6: Add a `describe('POST /v1/auth/resend-verification')` block to the tests, using `Mail::fake()`, with these cases:
  (a) An unverified user with an old token (`updated_at` older than 5 minutes, e.g. via `travel`) gets a new token and exactly one queued `UserVerification` (`Mail::assertQueued`). The old token then gets `token_mismatch` from `/auth/verify`.
  (b) The new token verifies through `POST /auth/verify` and returns 201.
  (c) An unknown email and an already-verified email both get the same 200 JSON body, and `Mail::assertNothingQueued()` / `assertNothingSent()` pass.
  (d) A second request inside the 5-minute cooldown queues no further mail and leaves the token string unchanged.
  (e) The 4th request for the same email within an hour returns 429, with the requests mixing upper and lower case.
  Only assert the 429 status in (e). `rate-limit-response-says-when-to-retry` is not merged: there is no `retry_after` / `too_many_requests` in `bootstrap/app.php` or `app/`. Clear the rate limiter between tests if the existing tests do that, and check TESTING.md for this.
  files: app/Api/Users/Tests/AuthTest.php
  depends: TASK-5
  risk: low. For (c), compare the full response body, not only `success`, so a different message can't leak whether the account exists.

TASK-7: Do the manual check from the brief's Additions with a real queue worker. Request a resend for an unverified account, confirm the mail arrives in the user's stored language, and confirm its link verifies. Record the result in implementation.md.
  files: none (implementation.md note only)
  depends: TASK-5
  risk: medium. This needs a running worker and mailer (e.g. the `log` mailer plus `queue:work` locally). If the developer agent can't run it in the container, it must say so explicitly rather than skip it silently. It may then need a human.

TASK-8: Run `composer test` and `composer analyse` in the worktree. Both must exit 0.
  files: none
  depends: TASK-1..TASK-6
  risk: low.

## Notes / open points

- No migration is needed. `verification_tokens` already has a unique `user_id` and `timestamps()` (`2025_11_22_150914_tokens_in_own_table.php`), so the cooldown can use `updated_at`.
- Email lookup casing in the service: the brief doesn't say. `forgotPassword` uses a plain `where('email', $email)`, which is case-insensitive on MariaDB and case-sensitive on SQLite. Mirror `forgotPassword` and don't add normalization. The limiter key is lowercased as the brief specifies.
- No new user-facing strings: the mail reuses the existing `mail.verification_*` keys. The API response message is English, as on the neighbouring endpoints.
- Out of scope, per the brief: hashing tokens, pruning unverified accounts, and queueing `forgotPassword`.
