# Tasks: Every library cover response sleeps for one second

Branch verified: `feature/generation_every-library-cover-response-sleeps-for-one-second`.

## TASK-1 — Remove `sleep(1)` from cover serving

Delete the `sleep(1);` line in `LibraryCoverController::show` so the cover response returns as soon as the file is read.

- Files: `app/Api/Libraries/Controllers/LibraryCoverController.php`
- Dependencies: none
- Risk: **low**. It is a one-line removal with no change to the API contract, and the existing `Cover serving` tests cover both paths.

## TASK-2 — Send immutable private cache headers on covers

Add `Cache-Control: private, max-age=31536000, immutable` to the 200 response in `LibraryCoverController::show`. Extend `Cover serving → serves a stored cover image` to assert that header, and assert that `Content-Type` is `image/jpeg`.

- Files: `app/Api/Libraries/Controllers/LibraryCoverController.php`, `app/Api/Libraries/Tests/LibraryRestApiTest.php` (lines ~408-418)
- Dependencies: TASK-1, because both edits touch the same lines.
- Risk: **low/medium**. File names come from `uniqid()` and are never reused (`LibraryCoverService::storeFile`), so the header is safe. `_preview` names are derived from the cover name. But `app/Api/Libraries/Commands/GenerateLibraryCoverPreviews.php` can regenerate a preview under the same name. If that regeneration ever produced visibly different bytes, browsers would keep the old preview for up to a year. The developer should confirm that the command only fills in missing previews or rewrites equivalent output. If it does not, raise this before shipping the header.
- Note: check the actual header string Laravel/Symfony emits. Symfony may normalise the directive order (e.g. `immutable, max-age=31536000, private`). Write the assertion against the emitted value, or check the individual directives with `$response->headers->getCacheControlDirective()`.

## TASK-3 (optional, brief §2.3) — Stream the cover instead of loading it into memory

Serve the file with `Storage::disk('user_data')->response($path, null, $headers)` instead of building the response from `Storage::get`. Keep the 404 behaviour, the mime mapping and the TASK-2 headers.

- Files: `app/Api/Libraries/Controllers/LibraryCoverController.php`, `app/Api/Libraries/Services/LibraryCoverService.php` (`loadCover` is the only reader). Before changing `loadCover`'s signature, grep for other callers.
- Dependencies: TASK-2
- Risk: **medium**. It changes the response type (StreamedResponse), the service signature and the way the tests read the body. The brief itself calls the gain small. Do this only if the job owner wants it. Otherwise, skip it.

## Out of scope / open question (brief §3)

The possible unauthenticated exposure through `php artisan storage:link` (`user_data` disk under `storage/app/public/user`, `config/filesystems.php:41-47`) has to be checked in the separate deployment repo. Moving covers to `private_user_data` is a product decision (should covers be private?) and touches storage layout and existing files. No task is planned here. Raise it with a human rather than change it in this job.

## Done criteria

`composer test` and `composer analyse` exit 0. `serves a stored cover image` runs in well under 0.1 s.
