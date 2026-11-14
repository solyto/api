# Verdict: Every library cover response sleeps for one second

id: generation
reviewer: reviewer
date: 2026-11-14

## Review

Branch: `feature/generation_every-library-cover-response-sleeps-for-one-second` (verified). Base: `main`.
Diff `main...HEAD` touches only `LibraryCoverController.php`, `LibraryRestApiTest.php` and the job docs, which matches `implementation.md`.

TASK-1: PASS
notes: `sleep(1);` is removed from `LibraryCoverController::show`. The API contract is unchanged.

TASK-2: PASS
notes: The 200 response now sends `Cache-Control: private, max-age=31536000, immutable`. The 404 path returns before the header is set. The test asserts `Content-Type: image/jpeg` and checks each directive with `getCacheControlDirective`, plus that `public` is absent, so Symfony's directive order doesn't matter.
The developer confirmed that `GenerateLibraryCoverPreviews` skips covers that already have a preview, and that is correct.
Non-blocking: the scheduled `ScaleCovers` job (`routes/console.php:25`, daily at 03:00) rewrites cover files in place under the same name with `scaleToWidth(400)`. So the new code comment ("the bytes behind a URL never change") is not strictly true. The rewritten file is the same picture at a smaller size, so a browser that cached the larger version still shows the right image. That makes this equivalent output under TASK-2's own rule, not a blocker.

TASK-3: N/A (optional, skipped as the tasks allowed)

Verification (run by reviewer in the worktree):
- `composer test`: exit 0. 1480 tests, all warnings come from the missing `.env` file, no failures.
- `composer analyse`: exit 0, no errors.
- `Cover serving → serves a stored cover image`: 0.03 s when run inside its test file. It shows about 1.2 s only when it is the first test of the process, which is boot overhead that every first test pays.

## Security

Not run. Brief §3 is still open: if the deployment runs `storage:link`, covers can be reached without authentication through `/storage/user/...`. This has to be checked in the deployment repo, and the fix is a product decision, so it was correctly left out of this job.

## Overall

APPROVED

Nothing must change before merge.
