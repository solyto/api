# Implementation: pdf-clipboards

id: maintain
developer: ebenezer
date: 2026-11-16

## Summary

Clipboard entries can now be documents (pdf, txt, md, csv, doc, docx, ppt,
pptx, xls, xlsx; 10 MB; 10 uploads per user per day), stored privately and
downloaded only by their owner as an attachment. Clipboard images moved from
the public `user_data` disk to `private_user_data`, so they are only reachable
through the authenticated `GET /clipboard/{id}/image`. Contract agreed with
the frontend (marmaduke) in room `pdf-clipboards`.

## Changes

TASK-1 (a2a5085): file entries
- `database/migrations/2026_11_16_120000_add_file_type_to_clipboards.php`:
  `type` enum gains `file`; nullable `file_name`, `file_size`, `mime_type`.
  Allowlisted in `tests/Unit/MigrationCompatibilityTest.php` (enum widening only).
- `config/clipboard.php`: `max_file_kb` (10240), `max_files_per_day` (10),
  `file_types` (extension => MIME types finfo may report; first is served).
- `StoreClipboardFileRequest`: extension and finfo-detected MIME must match
  the whitelist → 422 `errors.file`.
- `ClipboardService::storeFile` (413 over the size limit, random name on
  `private_user_data`, sanitized display name), `getFilePath`, `list(..., includeFiles)`;
  new `ClipboardFileService` for the private disk.
- `ClipboardController::storeFile` / `getFile` (attachment, canonical
  Content-Type, `nosniff`, `CSP: default-src 'none'; sandbox`); `list` takes
  `?include_files=1` — old builds (≤ 1.1.3) crash on a `file` entry, so it is
  opt-in (rule 13.3).
- `routes/api.php` + `AppServiceProvider`: `POST clipboard/file` behind
  `throttle:clipboard-files` (per user per day → 429 with `retry_after`),
  `GET clipboard/{clipboard}/file`.
- `ClipboardResource`: `file_name`, `file_size`, `mime_type`; `file_path` is
  null for files.
- `DeleteOverdueClipboardEntries`: removes stored files too.
- Tests: `ClipboardApiTest` (types, mismatches, 413, 429, opt-in list,
  ownership, delete), `JobTest`.

TASK-2 (bbf5dcd): images behind auth
- `ClipboardImageService` stores on `private_user_data`; delete/path go
  through `ClipboardFileService` for both images and files.
- `database/migrations/2026_11_16_120001_move_clipboard_images_to_private_disk.php`:
  moves existing clipboard image files (same relative path) from `user_data`
  to `private_user_data`; `down()` moves them back. Source is deleted only
  after a successful write.
- `ClipboardResource` keeps `file_path` for images: builds ≤ 1.1.3 render an
  entry as an image only when `file_path` is set, and otherwise crash on
  `content = null`.

## Known issues / follow-ups

- Released builds (1.0.0–1.1.3) render clipboard images from public
  `/storage/user/<file_path>`; after this job they show a broken thumbnail
  (copy still works). Accepted by the owner (2026-11-16): security over
  backwards compatibility here; no `min_client_version` bump.
- Deployment repo: PHP `upload_max_filesize` ≥ 10M, `post_max_size` above
  that, nginx `client_max_body_size` ≥ 10M — otherwise large uploads fail
  before the 413 check.
- The daily quota counts rejected attempts as well (standard throttle
  middleware); images are not counted.
- Other features still serve files from the public `user_data` disk (library
  covers, avatars, ...) — separate job.
- Migrations were only run in the test suite; not against any real database.
