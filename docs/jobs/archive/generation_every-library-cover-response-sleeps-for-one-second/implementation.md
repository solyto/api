## Summary

Removed the one-second `sleep(1)` from the library cover endpoint and made cover responses cacheable in the browser (`private, max-age=31536000, immutable`). `serves a stored cover image` now runs in ~0.03 s, down from ~2.6 s. `composer test` and `composer analyse` exit 0.

## Changes

TASK-1: Deleted `sleep(1);` in `LibraryCoverController::show` (`app/Api/Libraries/Controllers/LibraryCoverController.php`).

TASK-2: Added `Cache-Control: private, max-age=31536000, immutable` to the 200 response in the same controller. Extended `Cover serving → serves a stored cover image` (`app/Api/Libraries/Tests/LibraryRestApiTest.php`) to assert `Content-Type: image/jpeg` and to check the `private`, `max-age` and `immutable` directives (and that `public` is absent) through `getCacheControlDirective`, so Symfony's directive ordering doesn't matter. Confirmed the header is safe: `storeFile` names come from `uniqid()`, and `GenerateLibraryCoverPreviews` skips covers that already have a preview, so it never rewrites an existing preview. Missing previews return 404, which doesn't get the cache header.

TASK-3 (optional): Skipped. The tasks say to do it only if the job owner asks for it, and the brief calls the gain small.

## Known issues / follow-ups

- Brief §3 is still open: if the deployment runs `php artisan storage:link`, covers on the `user_data` disk can be reached without authentication at `/storage/user/...`. This needs checking in the deployment repo, plus a product decision on whether covers should be private.
- Sandbox note: the worktree had no `vendor/` or `storage/framework` directories. I ran `composer install` from the lock file and created the ignored storage directories so the suite could run. The test warnings in the run come only from the missing `.env` file.
