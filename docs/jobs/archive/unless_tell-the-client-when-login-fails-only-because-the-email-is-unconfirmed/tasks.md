# Tasks: email_unverified error code on login

Branch check: `.git` points at worktree `unless_tell-the-client-when-login-fails-only-because-the-email-is-unconfirmed`, which matches the brief. I couldn't run `git branch --show-current` (no shell in this session), so the developer should confirm it.

## TASK-1 — Add a typed `EmailUnverifiedException`
Add `App\Api\Users\Exceptions\EmailUnverifiedException extends \RuntimeException`, shaped like `InvalidDeletionTokenException`. Its message is `email_unverified`.

- Files: `app/Api/Users/Exceptions/EmailUnverifiedException.php` (new)
- Depends on: none
- Risk: low. This is a new class that copies an existing pattern.

## TASK-2 — Throw it from `LoginRequest::authenticate` for the unverified case
Replace the second `ValidationException` (`LoginRequest.php:31-35`) with `throw new EmailUnverifiedException()`. Leave the wrong-credentials `ValidationException` unchanged.

- Files: `app/Api/Users/Requests/LoginRequest.php`
- Depends on: TASK-1
- Risk: low. Only `AuthController::login` calls `authenticate()` (the developer should confirm with `grep -rn "authenticate()" app`).

## TASK-3 — Map it in `AuthController::login` to the coded 401
Add a `catch (EmailUnverifiedException)` that returns `ApiResponse::error('Please verify your email address first.', 401, ['email_unverified'])`. The existing `ValidationException` → `unauthorized()` catch stays. The new catch should not call `report()`, because this is an expected user state and not an error. Add a short description to the 401 `@OA\Response` that mentions the `email_unverified` code.

- Files: `app/Api/Users/Controllers/AuthController.php`
- Depends on: TASK-2
- Risk: low. The status stays 401 (rule 13.2) and only the error body changes. Rule 7.2 allows a typed exception caught in one controller.

## TASK-4 — Tests in `AuthTest.php` (`describe('POST /v1/auth/login')`)
- Extend the existing test at `:169` (unverified + correct password) to assert `->assertJsonPath('errors', ['email_unverified'])`, the same way the `token_expired` test does at `:339`.
- New: unverified user + wrong password → 401, and `errors` does not contain `email_unverified`.
- New: `config(['auth.policy.confirmation' => false])` + unverified user + correct password → 200 with a token.

- Files: `app/Api/Users/Tests/AuthTest.php`
- Depends on: TASK-3
- Risk: low. Tests only. `phpunit.xml` sets `EMAIL_CONFIRMATION=true`, so the config override is needed for the third case.

## TASK-5 — Verify
Run `composer test` and `composer analyse`. Both must exit 0. Then run `grep -rn "email_unverified" app/Api/Users`, which should return ≥ 1.

- Files: none
- Depends on: TASK-1–4
- Risk: low.

## Notes / open points
- The user-facing message stays English only, like the existing `verify` errors. The client is expected to use the code, not the message, so no translator handoff is planned. Flag it if that's wrong.
- No migrations, no new dependencies, and no DAV/pgsql changes.
