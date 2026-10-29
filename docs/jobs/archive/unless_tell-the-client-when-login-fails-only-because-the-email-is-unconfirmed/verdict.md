# Verdict: Tell the client when login fails only because the email is unconfirmed

id: unless
reviewer: reviewer agent
date: 2026-10-29

## Review

Diff reviewed: `git diff main...HEAD` on `feature/unless_tell-the-client-when-login-fails-only-because-the-email-is-unconfirmed`. Source changes are limited to the four files the tasks named. No changes are out of scope.

TASK-1: PASS
notes: `app/Api/Users/Exceptions/EmailUnverifiedException.php` copies the shape of `InvalidDeletionTokenException` exactly, with the message `email_unverified`.

TASK-2: PASS
notes: `LoginRequest::authenticate` throws `EmailUnverifiedException` only when the confirmation policy is on and `email_verified_at` is null, which is after `Auth::attempt` has succeeded. The wrong-credentials `ValidationException` is unchanged, and its import is still used. `AuthController::login` is the only caller (grep confirmed).

TASK-3: PASS
notes: `AuthController::login` catches the new exception before the `ValidationException` catch and returns `ApiResponse::error('Please verify your email address first.', 401, ['email_unverified'])` without calling `report()`. The status stays 401 (rule 13.2). The 401 `@OA\Response` description mentions the code.

TASK-4: PASS
notes: `AuthTest.php` covers all three cases from the brief: unverified with the correct password gets `errors == ['email_unverified']`, unverified with a wrong password gets a 401 without the code, and with confirmation off the user logs in with a 200 and a token.

TASK-5: PASS
notes: `composer test` exits 0 (1248 tests, 4322 assertions). Every test shows a "warning" because the sandbox has no `/workspace/.env` (phpdotenv `file_get_contents` in `tests/TestCase.php:21`). This is environmental and not a failure. `composer analyse` reports `[OK] No errors`. `grep -rn email_unverified app/Api/Users` returns 6 hits (at least 1 was required).

## Security

No enumeration risk: the code is returned only after `Auth::attempt` has succeeded, and the wrong-password case is tested to return the plain 401.

## Overall

APPROVED. Nothing blocks the merge.
