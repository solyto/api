# Tasks: Any user can copy server files into the public disk through a dev-request screenshot

id: case
analyst: analyst
date: 2026-11-18

<!-- Produced by @analyst from brief.md. -->

## Scope

In scope: brief steps 1–3 plus the regression tests, and step 5 (throttle) as a
separate, optional task. No new dependency, no migration, no DAV/`pgsql` change.

Out of scope (not code in this repo):
- Step 4 (scan `storage/app/public/dev-requests/*/` on production, rotate secrets
  if needed). That is an ops task for the maintainer after deploy.
- Checking whether production serves `/storage` and runs `*.php` there. That is in
  the deployment repo.
- The "Side note: performance" (`DevRequestService::list` vote loading). Not part
  of this fix.

## Open points (developer: don't guess, follow the defaults below or stop)

1. **Frontend payload is unconfirmed** (brief "Not verified"). The frontend repo
   is not in this workspace. Default: accept `data:image/(png|jpeg|gif|webp);base64,<b64>`,
   which is what `FileReader.readAsDataURL` produces and what the brief assumes.
   Before merge, the maintainer must confirm this against the released
   frontend (oldest supported build 1.0.15, STANDARDS 13.1). If the frontend
   sends anything else (raw base64 without the `data:` prefix, `image/jpg`,
   multipart upload), the regex has to widen before deploy, or every
   screenshot upload starts failing with 422.
2. **Size cap value is not given in the brief.** Default: decoded screenshot ≤ 5 MB
   (base64 string `max` ≈ 7 000 000 chars). `description`: `max:65535` (the column is
   MariaDB `TEXT`, so anything longer fails in the DB today anyway). Note both values
   in implementation.md so the maintainer can change them.
3. **Admin `PUT /dev-requests/{id}` still accepts any `screenshot` string**
   (`UpdateDevRequestRequest.php:16`). It only overwrites the stored file name (no
   file I/O), so the resource could point at a different path under
   `dev-requests/{id}/`. It is admin-only and not part of the brief, so leave it
   as is and list it under follow-ups in implementation.md.

## Task breakdown

TASK-1: Rewrite `DevRequestScreenshotService::save` so it never passes request input to
`putFileAs`/`fopen`. Match the `data:image/(png|jpeg|gif|webp);base64,` prefix, run
`base64_decode(..., true)`, check the bytes with `getimagesizefromstring` (same idea as
`RepairCorruptedVCards.php:179-181`), take the extension from the matched MIME type
(`jpeg` → `jpg` is fine, but be consistent), and write with
`Storage::disk('public')->put()`. Return the stored file name, or `false`/throw on
invalid input. Remove the `screenshot_name`-based `getFileName` (or make it ignore the
client name). Drop the stray-space path bug (`'dev-requests/ '`) along the way, since
that line gets rewritten anyway.
  files: app/Api/DevRequests/Services/DevRequestScreenshotService.php
  depends: none
  risk: high. This is the security fix itself; a regex that is too loose leaves the hole open, and one that is too strict breaks uploads from the real frontend (open point 1).

TASK-2: Update `DevRequestService::create` to use the new `save` result. Store the file
name it returns, stop reading `screenshot_name` for anything, and make sure a bad or
missing name can no longer throw after `DevRequest::create`. Either decode/validate
before the insert, or wrap insert and save in a transaction, so no orphan row is
left behind.
  files: app/Api/DevRequests/Services/DevRequestService.php
  depends: TASK-1
  risk: medium. Changes the create flow order; the screenshot column value must stay `screenshot.<ext>` so `DevRequestResource` keeps returning `dev-requests/{id}/screenshot.<ext>` (STANDARDS 13.2).

TASK-3: Tighten `StoreDevRequestRequest` (STANDARDS 3.1). `screenshot`:
`nullable|string|max:<cap>|regex:/^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+\/]+=*$/`.
`screenshot_name`: keep `nullable|string|max:255` and leave it unused, so old clients
still pass. `description`: add `max:65535`. Update the `@OA\RequestBody` for
`devRequestStore` in the same commit (STANDARDS 2.6): `screenshot` gets a
pattern/description, `description` gets `maxLength`, and `screenshot_name` is marked
ignored. Add a `ValidationErrorResponse` ref for 422 if the file already uses it
(STANDARDS 2.4).
  files: app/Api/DevRequests/Requests/StoreDevRequestRequest.php, app/Api/DevRequests/Controllers/DevRequestController.php (annotation only)
  depends: none (the values must match TASK-1)
  risk: medium. New 422s on `POST /dev-requests` are a contract change for any client that sends a non-matching payload (open point 1).

TASK-4: Add a FormRequest for voting (e.g. `VoteDevRequestRequest`) with
`vote: present|nullable|in:up,down`. Use it in `DevRequestController::vote` with
`$request->validated('vote')`, and remove the unused
`$devRequest->load(['votes', 'comments'])` (`:206`). Fix the `@OA` body:
`vote` is `type="string", enum={"up","down"}, nullable=true`, not `integer`. Also add
the 422 response ref.
  files: app/Api/DevRequests/Requests/VoteDevRequestRequest.php (new), app/Api/DevRequests/Controllers/DevRequestController.php
  depends: none
  risk: low. The existing tests already send `up`/`down`/`null`; only invalid values change, from 500 to 422.

TASK-5: Add regression tests to `app/Api/DevRequests/Tests/DevRequestApiTest.php`
(`Storage::fake('public')`, `Notification::fake()`, normal user via `makeUser()`):
  - local path in `screenshot` (e.g. `base_path('composer.json')`) → 422, nothing written, no row
  - `http://…` URL → 422
  - `data://text/plain;base64,…` → 422
  - a valid PNG data URI with `screenshot_name: x.php` → 201, stored as `dev-requests/{id}/screenshot.png`, no `.php` file on the disk, resource `screenshot` is `dev-requests/{id}/screenshot.png`
  - a valid PNG data URI without `screenshot_name` → 201 (no 500), and the row count goes up by exactly one
  - a `data:image/png;base64,` prefix with non-image bytes → 422 or no file written (whichever TASK-1/3 settle on)
  - `vote: sideways` → 422, no vote row
  Check that `ClientContractTest` still passes.
  files: app/Api/DevRequests/Tests/DevRequestApiTest.php
  depends: TASK-1, TASK-2, TASK-3, TASK-4
  risk: low. Tests only, using a real tiny PNG (base64 literal or `UploadedFile::fake()->image()` contents) so `getimagesizefromstring` passes.

TASK-6 (optional, brief step 5): Add a `throttle:dev-requests` limiter on
`POST /dev-requests` only. Register it in `AppServiceProvider` next to
`feeds-import`/`clipboard-files`, per user. The reason is that each create sends an
ntfy push to the admins.
  files: app/Shared/Providers/AppServiceProvider.php, routes/api.php
  depends: none
  risk: low. The limit value is not in the brief; use something generous (e.g. 10/hour per user) and note it in implementation.md. Skip this task if the maintainer wants it out of scope.

TASK-7: Run `composer test` and `composer analyse` in the worktree. Both must exit 0.
Record in implementation.md the chosen size caps, the throttle value (if TASK-6 was
done), the unconfirmed frontend payload (open point 1), the admin `PUT` screenshot
follow-up (open point 3), and the production check from brief step 4.
  files: docs/jobs/case_…/implementation.md
  depends: TASK-1 to TASK-6
  risk: low. Verification and notes only.
