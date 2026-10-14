# Verdict: Client version header

id: heat
reviewer: @reviewer
date: 2026-10-14

## Review (re-review after rework)

Branch verified: `feature/heat_client-version-header`. I reviewed the diff against the base `main` (`git diff main...HEAD`). Only the files listed in tasks.md and the job docs changed. Nothing changed outside the planned scope. `.env.testing` shows as modified in the working tree, but it is not committed and is not part of the diff (see implementation.md: it is a bind mount).

TASK-1: PASS
notes: `config/cors.php:27` now includes `X-Client-Version` in `allowed_headers`. `exposed_headers` is still `[]`.

TASK-2: PASS
notes: The migration is additive: `string('client_version', 32)->nullable()->after('last_used_at')`, and `down()` drops the column. It uses the default connection, not `pgsql`. It is shown in implementation.md and has not been run against a real database.

TASK-3: PASS
notes: `app/Shared/Middleware/RecordClientVersion.php`. The previous finding is fixed. The middleware now writes with `PersonalAccessToken::whereKey($token->getKey())->update([...])`, which cannot INSERT. A token deleted by `auth/logout` or `auth/refresh` therefore stays deleted, because the update affects 0 rows. The rest is unchanged and correct:
- it records after `$next` (needed because of the ordering with `auth:sanctum`)
- it uses the anchored, length-bounded regex
- it narrows with `instanceof PersonalAccessToken`, so `TransientToken` and no-user requests are skipped
- it only writes when the value changed
- it never rejects a request and never logs the value

TASK-4: PASS
notes: `bootstrap/app.php` calls `$middleware->appendToGroup('api', RecordClientVersion::class)`. It runs on the `api` group only, so `routes/web.php` (DAV, Telegram) is unaffected.

TASK-5: PASS
notes: `app/Api/Users/Tests/ClientVersionHeaderTest.php` covers every case in the brief with real bearer tokens. It now also has the regression tests I asked for: logout and refresh with a valid header leave the old token row deleted, and the old plain token gets 401.

TASK-6: PASS
notes: I ran both commands myself in the worktree. `composer test` exits 0 (791 tests, 2236 assertions; the only warnings are the existing `.env` `file_get_contents` warnings). `composer analyse` exits 0 (`[OK] No errors`).

## Overall

APPROVED

No blockers.
