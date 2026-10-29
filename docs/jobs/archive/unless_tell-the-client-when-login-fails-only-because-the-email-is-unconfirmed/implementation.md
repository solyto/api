# Implementation: Tell the client when login fails only because the email is unconfirmed

id: unless
developer: developer agent
date: 2026-10-29

## Summary

Login with correct credentials but an unverified email (with `auth.policy.confirmation` on) now returns 401 with `errors: ['email_unverified']`. Wrong credentials still return the plain `unauthorized()` 401. The status is unchanged (rule 13.2).

## Changes

TASK-1: New `app/Api/Users/Exceptions/EmailUnverifiedException.php`, modelled on `InvalidDeletionTokenException`.
TASK-2: `LoginRequest::authenticate` throws `EmailUnverifiedException` for the unverified case. The wrong-credentials `ValidationException` is unchanged. `AuthController::login` is the only caller (confirmed with grep).
TASK-3: `AuthController::login` catches `EmailUnverifiedException` and returns `ApiResponse::error('Please verify your email address first.', 401, ['email_unverified'])` without calling `report()`. The 401 OpenAPI description now mentions the code.
TASK-4: `AuthTest.php`: the existing unverified test now asserts `errors == ['email_unverified']`. Added two tests: unverified user with a wrong password gets a 401 without the code, and confirmation off lets the unverified user log in (200 with a token).
TASK-5: `composer test` and `composer analyse` both exit 0. `grep -rn email_unverified app/Api/Users` returns 6 hits.

## Known issues / follow-ups

- One full-suite run had 2 failures in `App\Api\Export\Tests\ExportTest` (`TodoExportService`/`FinanceWealthExportService writes a csv`, ErrorException). They pass in isolation and in two later full runs. This looks like a pre-existing flaky test unrelated to this job.
- The message is English only, like the existing `verify` errors. Clients are expected to key on the code.
- `.env.testing` shows as modified in `git status`, but git can't hash it (it's a mount in this sandbox). I left it untouched.
