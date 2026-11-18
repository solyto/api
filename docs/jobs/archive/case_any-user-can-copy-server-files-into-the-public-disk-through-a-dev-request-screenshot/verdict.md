# Verdict: Any user can copy server files into the public disk through a dev-request screenshot

id: case
reviewer: reviewer
date: 2026-11-18

## Review

Reviewed `git diff main...HEAD`. Every code change is covered by tasks.md and implementation.md. Nothing is out of scope.

TASK-1: PASS
notes: `DevRequestScreenshotService::decode` anchors on `data:image/(png|jpeg|gif|webp);base64,`, decodes with `base64_decode(..., true)`, and checks the bytes with `getimagesizefromstring`. The extension comes from the detected MIME, never from the client. `save()` writes the bytes with `Storage::put`, so no request input reaches `putFileAs`/`fopen`. The stray-space path, `getFileName` and `createDir` are gone.

TASK-2: PASS
notes: `DevRequestService::create` decodes before the insert, so an invalid screenshot leaves no row. Insert and write run in one `DB::transaction`, and a failed write throws and rolls back. The column value stays `screenshot.<ext>`, so the resource path shape is unchanged (13.2). `screenshot_name` is no longer read.

TASK-3: PASS
notes: The rules match the service. 422 now references `ValidationErrorResponse` (2.4). The `@OA\RequestBody` is updated in the same commit (2.6). Measured on a ~6.7 MB valid payload: the regex matches with and without JIT. Malformed long input hits the PCRE backtrack limit, which makes the rule fail closed (422).

TASK-4: PASS
notes: `VoteDevRequestRequest` has only `rules()` (3.4). The controller uses `validated('vote')`. The unused `load(['votes','comments'])` is removed. The annotation is corrected to a nullable string enum, and 422 references `ValidationErrorResponse`.

TASK-5: PASS
notes: All the listed regression cases are present: local path, http URL, `data://text/plain`, image prefix with non-image bytes, `.php` name, mismatched declared type, missing name, `vote: sideways`. They assert that no row and no file are written where relevant.

TASK-6: PASS
notes: A `dev-requests` limiter (10/hour per user) is applied only to `POST /dev-requests`, with a 429 test. A new 429 status on an existing endpoint is acceptable under 13.2 (older clients treat any non-OK as failure), and the value is documented for the maintainer.

TASK-7: PASS
notes: The reviewer ran both checks in the worktree. `composer test` exited 0 (all tests pass). The 1791 warnings are all `file_get_contents(.env)` because this environment has no `.env`. `composer analyse` exited 0 with no errors.

## Security

The reported holes are closed: local file read, SSRF via URL wrappers, and `.php` written to the public disk. Out-of-repo steps (brief step 4: scan production `dev-requests/` and rotate secrets if needed; check whether `/storage` is served and runs PHP) are listed in implementation.md as ops follow-ups.

## Overall

APPROVED

No code blockers. The maintainer must confirm one thing before deploy (tasks.md open point 1): released frontends (oldest 1.0.15) must send `data:image/(png|jpeg|gif|webp);base64,…`. Otherwise screenshot uploads from those builds start returning 422.
