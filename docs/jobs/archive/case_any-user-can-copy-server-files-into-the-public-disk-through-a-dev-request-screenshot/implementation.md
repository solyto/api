# Implementation: Any user can copy server files into the public disk through a dev-request screenshot

id: case
developer: developer
date: 2026-11-18

## Summary

`POST /dev-requests` no longer passes the `screenshot` value to `putFileAs`/`fopen`. The value must be a `data:image/(png|jpeg|gif|webp);base64,` URI. It is decoded strictly and checked with `getimagesizefromstring`, then written with `Storage::put`. The file extension comes from the detected image type, never from `screenshot_name` (which is now ignored). Invalid screenshots return 422 before any row is inserted. Votes are validated (422 instead of 500), and dev-request creation is throttled.

`composer test` and `composer analyse` both exit 0. The test run prints "warnings" because the worktree has no `.env` file. That comes from the environment and is not a failure.

## Changes

TASK-1: `DevRequestScreenshotService`: new `decode()` (prefix match, `base64_decode(..., true)`, `getimagesizefromstring`, extension from the detected MIME: png/jpg/gif/webp, otherwise a `ValidationException` on `screenshot`). `save()` now takes bytes + extension and uses `put()`. Removed `getFileName()`, `createDir()` and the `'dev-requests/ '` stray-space path.
TASK-2: `DevRequestService::create`: decodes the screenshot before the insert, so a bad screenshot leaves no row. Insert and file write run in one `DB::transaction`, and a failed write throws, which rolls the row back. The column value stays `screenshot.<ext>`, so the resource path shape is unchanged. `screenshot_name` is no longer read.
TASK-3: `StoreDevRequestRequest`: `screenshot` is `nullable|string|max:7000000|regex:<data URI>`, `description` gets `max:65535`, and `screenshot_name` is kept but unused. The `devRequestStore` annotation is updated (pattern, maxLength, `screenshot_name` marked ignored, 422 → `ValidationErrorResponse`).
TASK-4: New `VoteDevRequestRequest` (`vote: present|nullable|in:up,down`), used in `DevRequestController::vote`. Dropped the unused `load(['votes', 'comments'])`. The annotation now says `vote` is a nullable string enum, and 422 → `ValidationErrorResponse`.
TASK-5: Regression tests in `DevRequestApiTest.php`:
- 422 with no row and no file for a local path, an `http://` URL, `data://text/plain`, and an image prefix with non-image bytes
- `x.php` name → stored as `screenshot.png`
- a PNG declared as `image/jpeg` → stored as `.png`
- no `screenshot_name` → 201
- `vote: sideways` → 422
TASK-6: Rate limiter `dev-requests` (10 per hour per user) in `AppServiceProvider`, applied to `POST /dev-requests` only. There is a test for the 11th request → 429.
TASK-7: Full `composer test` and `composer analyse` run green.

Values to confirm or change:
- Screenshot cap: 7 000 000 base64 chars, about 5 MB decoded.
- `description`: max 65 535 (the MariaDB `TEXT` limit).
- Throttle: 10 dev requests per hour per user.

## Known issues / follow-ups

- **The frontend payload is unconfirmed.** The accepted format is `data:image/(png|jpeg|gif|webp);base64,…`, which is what `FileReader.readAsDataURL` produces. Before deploying, confirm this against the released frontends (oldest 1.0.15). If they send raw base64, `image/jpg`, other types or multipart, every screenshot upload will 422.
- **Admin `PUT /dev-requests/{id}`** still accepts any `screenshot` string (`UpdateDevRequestRequest`). It does no file I/O, but it can point the resource at another name under `dev-requests/{id}/`. It is left as is (out of scope).
- **Production check (brief step 4), ops:** after deploy, run `file --mime-type` over `storage/app/public/dev-requests/*/`. Any non-image means the hole was used, and the secrets that file could expose need rotating. Also check in the deployment repo whether `/storage` is served and whether `*.php` there reaches PHP-FPM.
- The `DevRequestService::list` vote-loading performance note from the brief was not addressed.
