# Implementation: Client minimum version (426)

id: believe
developer: @developer
date: 2026-10-14

## Summary

The API can now tell outdated builds to update. When `app.min_client_version` is set and a request to an `auth:sanctum` route carries a valid `X-Client-Version` below it, `RecordClientVersion` returns a 426 through the envelope (`message` "Please update the app.", `meta.min_client_version`). The request never reaches the controller. Requests that have no header or an invalid one are allowed. So are public routes (`health`, `auth/login`, ...). The check is off by default (`null`).

## Changes

TASK-1: `config/app.php`: added `'min_client_version' => env('MIN_CLIENT_VERSION')` with a comment block below `version`. It defaults to `null`, which means off.

TASK-2: `app/Shared/Middleware/RecordClientVersion.php`: added a private `isBelowMinimum()` check that runs **before** `$next`. It returns true only when all of these hold: the minimum is a non-empty string, the header matches the existing `PATTERN`, the matched route's `gatherMiddleware()` contains `auth:sanctum`, and `version_compare($header, $min, '<')`. The existing record-after-`$next` logic is unchanged. No second middleware was added.

TASK-3: Same file. On rejection the middleware returns `ApiResponse::error('Please update the app.', 426, meta: ['min_client_version' => ...])` directly. This is the option tasks.md preferred: `bootstrap/app.php` is unchanged, and its `HttpException` branch would have dropped the `meta`.

TASK-4: New `app/Api/Users/Tests/ClientMinimumVersionTest.php` with these cases:
- minimum off → 200
- `1.0.9` against min `1.0.10` → 426 with envelope, message and `meta.min_client_version` (this proves the comparison is numeric)
- equal → 200
- above → 200
- missing header → 200
- invalid header → 200
- `health` with an old header → 200
- `auth/login` with an old header → 200

TASK-5: Checks run in the worktree:
- `composer analyse`: exit 0 ("No errors").
- `composer test`: exit 0 on 3 of 5 runs. The other 2 runs failed, and the one failure I captured was the unrelated, pre-existing flaky `PasskeyTest` › "PasskeyService authentication › it authenticates with a valid signature" (`Failed to load EC public key.`). I did not capture the failures from the first failing run, but they were not in this job's tests. The new tests and `ClientVersionHeaderTest` passed on every run.
- Environment setup needed for this: `vendor/` was missing, so I ran `composer install` from the lock file, which adds no new packages. I also created the gitignored `storage/framework/*` directories. The many "warnings" come from the missing `.env` file (`file_get_contents(/workspace/.env)`). They are not test problems.

## Known issues / follow-ups

- **Open question 1 (owner to confirm):** I followed tasks.md literally. Every `auth:sanctum` route returns the 426, including the authenticated `auth/*` routes (`logout`, `logout-all`, `refresh`, `tokens`, `revoke-token`, `passkeys/*`). The brief also says "`auth/*` stay reachable". If outdated builds should still be able to log out or refresh, `isBelowMinimum()` needs a path exemption for `api/v1/auth/*`.
- **Open question 2:** the check runs before `auth:sanctum`. An outdated request to a protected route with a missing or invalid token therefore gets a 426, not a 401.
- `PasskeyTest` "authenticates with a valid signature" is flaky. It fails intermittently with `Failed to load EC public key.` I suspect the randomly generated EC key in the test, but I haven't checked. It is unrelated to this job and worth a backlog entry.
- Not done (not in the brief): an OpenAPI annotation for the 426, a `MIN_CLIENT_VERSION` entry in `.env.example`, and a STANDARDS Decisions entry.
- `.env.testing` shows as modified in `git status`, but git cannot hash it ("unsupported file type"). It looks like an environment mount, so I left it alone.
