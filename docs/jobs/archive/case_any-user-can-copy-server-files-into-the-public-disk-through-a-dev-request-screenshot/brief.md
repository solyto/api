# Brief: Any user can copy server files into the public disk through a dev-request screenshot

type: feature
id: case
branch: feature/case_any-user-can-copy-server-files-into-the-public-disk-through-a-dev-request-screenshot
date: 2026-11-18
author: Leander Muskalla

Source report: `docs/reports/dev-request-screenshot-reads-server-files-2026-11-18.md`

## Summary

**Severity: high.** Any logged-in user can do this, and anyone can register.

The `screenshot` field is validated only as `nullable|string` (`StoreDevRequestRequest.php:15`). Its value goes unchanged into `Storage::disk('public')->putFileAs($dir, $file, $name)` (`DevRequestScreenshotService.php:32`). When `$file` is a string, Laravel treats it as a **path to open**: `fopen(is_string($file) ? $file : $file->getRealPath(), 'r')` (`vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemAdapter.php:478`). Whatever that path names gets copied to `storage/app/public/dev-requests/{id}/screenshot.<ext>`. The extension comes from the client-supplied `screenshot_name`, unchecked (`getFileName`, `:9-15`).

The feature presumably works today because the app sends a `data:` URI, which `fopen` happens to accept. But the same call also accepts:

| `screenshot` value | effect |
|---|---|
| `/run/secrets/<name>`, `/var/www/.env`, `/proc/self/environ`, any file PHP can read | copied into the public disk (**secret disclosure**) |
| `http://<internal-host>/…` (`allow_url_fopen=1`, the PHP default and on in this container) | server fetches it and stores the response (**SSRF**, bypasses `OutboundUrlGuard`) |
| `data://text/plain;base64,<php code>` + `screenshot_name: x.php` | writes `screenshot.php` with attacker content into the public disk |

The stored file only reaches the attacker if the public disk is web-served. `DevRequestResource` returns a *relative* path (`dev-requests/{id}/screenshot.ext`, `DevRequestResource.php:63`), and the list is visible to every user. A relative path is only useful to a client if `/storage/…` is served, which suggests `storage:link` exists in production. **This must be checked in the deployment repo.** The open `user_data` question from the library-cover job asks the same thing.

If `/storage` is served *and* the web server hands `*.php` under `public/` to PHP-FPM (the stock Laravel nginx config does: `location ~ \.php$`), the third row becomes **remote code execution**.

## Measured

Throwaway Pest test, `Storage::fake('public')`, `Notification::fake()`, logged in as a normal (non-admin) user via `makeUser()`:

| request | result |
|---|---|
| `screenshot: base_path('composer.json')`, `screenshot_name: x.json` | 201. `dev-requests/1/screenshot.json` holds the contents of `composer.json` |
| `screenshot: data://text/plain;base64,…('<?php echo 1;')`, `screenshot_name: x.php` | 201. `dev-requests/2/screenshot.php` holds `<?php echo 1;` |
| `screenshot: data:image/png;…`, no `screenshot_name` | **500**, but the dev request row was **already inserted** (count went 2 → 3). `getFileName(null)` throws a `TypeError` after `DevRequest::create` |
| `POST /dev-requests/1/vote` with `vote: sideways` | **500** "A database error occurred." `vote` is never validated (`DevRequestController.php:208`). Only the DB enum stops it |

The same `putFileAs` behaviour was reproduced outside the app with a bare `FilesystemAdapter` (data URI, local path, `.php` name: all three written).

## Suggestion

One small job, no new dependency, no schema change. Keep accepting what the app sends today (STANDARDS 13.1). **Confirm the exact payload the frontend sends first**; the analysis assumes a `data:image/…;base64,` string.

1. **Never pass request input to `putFileAs`/`fopen`.** In `DevRequestScreenshotService::save`:
   - accept only `^data:image/(png|jpeg|gif|webp);base64,(…)$`
   - `base64_decode(..., true)`, rejecting on failure
   - write the bytes with `Storage::disk('public')->put($path, $bytes)`
   - **derive the extension from the matched MIME type, not from `screenshot_name`.** Optionally confirm with `getimagesizefromstring` that the bytes really are an image.
2. **Validate in the FormRequest (STANDARDS 3.1).** Add a `regex:` (or a small closure rule) for `screenshot`, plus a size cap. `description` has no `max` either, and the decoded screenshot is unbounded apart from `post_max_size`. `screenshot_name` becomes informational or can be dropped from use. Make it `required_with:screenshot` or simply ignore it, so the missing-name 500 goes away.
3. **Validate `vote`** in a FormRequest: `present|nullable|in:up,down`. Drop the unused `$devRequest->load(['votes', 'comments'])` in the controller (`:206`). It loads every comment body only for the service to reload `votes` right after.
4. **Clean up after the fact.** Once deployed, list `storage/app/public/dev-requests/*/` on production and look for any screenshot that isn't an image (`file --mime-type`). Anything else means the hole was used. If something turns up, rotate the secrets that file could have exposed.
5. **Optional hardening:** add a `throttle:` limiter on `POST /dev-requests` like the other write endpoints that cost something. Each create sends an ntfy push to the admins (`DevRequestService.php:53`), so today one user can flood that topic.

Regression tests for the module's `Tests/` (they mirror the probe above):
- a local path in `screenshot` is rejected with 422
- an `http://` URL is rejected with 422
- a `.php` `screenshot_name` with a valid PNG data URI is stored as `screenshot.png`
- a missing `screenshot_name` does not 500
- `vote: sideways` is a 422

## Side note: performance (low priority)

`DevRequestService::list` (`:22-32`) eager-loads **every vote of every request** for every caller. It then counts up/down votes twice in PHP: once to sort (`score()`), once more in the resource. The cost is one row per user per request, uncached and unpaginated. Small today because `DeleteOldDevRequests` prunes finished requests after 30 days. If it ever shows up in Pulse:
- use `withCount(['votes as upvotes' => fn ($q) => $q->where('vote_type', 'up')], …)` and sort in SQL
- load only the caller's own vote (`with(['votes' => fn ($q) => $q->where('user_id', $userId)])`) for `user_vote`

## Not verified

- Whether production serves `storage/app/public` at `/storage` and how it treats `.php` there. That lives in the deployment repo.
- Which files the PHP-FPM user can read in the production container. Docker secrets under `/run/secrets` are usually readable by the app user, since the app reads them via `DockerSecretHelper`.
- The exact `screenshot` payload the frontend sends.
- Whether the hole has been used. See step 4.
