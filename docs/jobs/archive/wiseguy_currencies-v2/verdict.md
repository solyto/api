# Verdict: currencies-v2

id: wiseguy
reviewer: richie
date: 2026-11-25

Reviewed diff `8b96d2e..35f5e42` on `feature/wiseguy_currencies-v2` against
`docs/STANDARDS.md`.

## Review

TASK-1: PASS
notes: Migration `database/migrations/2026_11_25_000001_add_currency_to_user_settings.php:12`
adds `string('currency', 3)->default('EUR')->after('week_start')` — NOT NULL by
default, `EUR` default, `->after()` matches the 46 existing uses — with a
`dropColumn` `down()`. `app/Api/Users/Models/UserSettings.php:28` adds `currency`
to `$fillable`; `app/Api/Users/Factories/UserSettingsFactory.php:22` defaults it
to `EUR`. Additive, no backfill needed. Not run against a real database.

TASK-2: PASS
notes: `app/Api/Users/Requests/UpdateCurrencyRequest.php:12` is
`required|string|in:EUR,USD,GBP,CHF,CAD,AUD,RUB` — exactly the agreed 7 codes.
`app/Api/Users/Services/UserSettingsService.php:76-79`
`updateCurrency()` mirrors `updateWeekStart()`. The controller action
(`app/Api/Users/Controllers/UserSettingsController.php:258-293`) carries a full
`@OA\Put` block byte-for-byte in the style of `updateWeekStart` (path
`/users/me/settings/currency`, `operationId="userSettingsUpdateCurrency"`,
request-body enum, `SuccessResponse` envelope with `data`) and returns
`ApiResponse::success(null, 'Currency updated successfully.')`.
`routes/api.php:130` registers it inside the `users/me/settings` prefix.

TASK-3: PASS
notes: `app/Api/Users/Resources/UserSettingsResource.php:20` adds the schema
property with the 7-code enum, and `:46` returns `$this->currency ?? 'EUR'`.
`app/Api/Users/Tests/UserApiTest.php:492-521` covers: update persists (`USD`),
invalid code (`MXN`) returns 422 with a `currency` validation error and leaves
the stored value (`GBP`) unchanged, and `/users/me` returns `EUR`.

Cross-cutting (standards): paths relative to the server URL and matching
`routes/api.php` (2.1/2.2), `SuccessResponse` envelope reused (2.3), unique
`<resource><Action>` operationId (2.5), rules + `@OA\RequestBody` + resource
schema in one change (2.6), pipe-string FormRequest in `Requests/` with no
inline validation (3.1), no `authorize()` (3.3), and schema properties equal
`toArray()` keys (6.2). Diff is scoped to the task: no unrelated edits, no
`docs/policies/` or `docs/AGENTS.md` changes.

## Security

None found. The action operates only on `$request->user()`'s own settings,
input is whitelist-validated, and the change adds no raw SQL, secrets, or new
surface. (Reviewed by @reviewer; @security not run.)

## Overall

NEEDS WORK

Single blocker — independent verification. `composer test` and
`composer analyse` could not be executed in this review session: the shell
permits git only, so both commands were refused before running. The
implementer reports `composer test` 2420 passed / `composer analyse` no errors
(`implementation.md`), but per the review bar an unrun suite is NEEDS WORK.

No code change is required by this review. Run the full suite in a normal
worktree (`composer test` and `composer analyse` both exit 0); if green, the
job is APPROVED.

Note (environment, not a branch change): `.env.testing` appears modified in
`git status` but is a `/dev/null` character device (`git` reports "unsupported
file type") — environment-only, must not be committed.
