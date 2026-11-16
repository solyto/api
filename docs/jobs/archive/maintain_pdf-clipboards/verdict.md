# Verdict: pdf-clipboards

id: maintain
reviewer: ebenezer (self-review at the owner's request — the same agent implemented the job, so this is not an independent review)
date: 2026-11-16

## Review

Checked: full diff `bf939a8..HEAD`, STANDARDS.md (sections 1–13), TESTING.md,
`composer test` (all green) and `composer analyse` (no errors) in the worktree.

TASK-1: PASS
notes:
- Contract matches the room decisions: `POST /clipboard/file`, `GET /clipboard/{id}/file`,
  413 / 422 / 429, `?include_files=1` opt-in (rule 13.3; 1.0.15–1.1.3 render unknown
  types as text).
- Found and fixed during review (a82178b): file entries returned `content: null` on
  store but `''` on list (`content` is NOT NULL, default `''`); now `''` everywhere,
  contract corrected in the room.
- Rules 2.4/2.6 (422 references `ValidationErrorResponse`, schema + annotations in
  the same commit), 3.1/3.3/3.4 (FormRequest with `rules()` only), 5.1, 7.2/7.3
  (service throws, central map renders), 8.2 (cache forgotten on write), 9.3, 10.1: met.
- The enum-widening migration is allowlisted in `MigrationCompatibilityTest` with a reason.
- Minor: `ClipboardImageService` repeats the `private_user_data` disk name that
  `ClipboardFileService::DISK` holds; `getImagePath()` keeps its unused `$userId`
  parameter (pre-existing). Not blocking.

TASK-2: PASS
notes:
- Images are written to `private_user_data`; the data migration moves existing
  ones (source deleted only after a successful write; `down()` reverses) and is
  covered by a test, including a missing source file.
- `file_path` kept on image responses; pinned by a new client contract test
  (rule 13.6 — the clipboard had none) (a82178b).
- Found and fixed during review (a82178b): `GET /clipboard/{id}/image` returned 500
  when the file was missing (more likely now during the move); now 404, tested.
- Breaking thumbnails in builds 1.0.0–1.1.3 is an owner decision (room, 2026-11-16).

## Security

- Storage: random 128-bit names on `private_user_data` (outside the web root); the
  original name is display-only, stripped of control characters, quotes and slashes.
  `file_path` is not returned for files. Paths are never user-controlled.
- Download: owner check (403), attachment, canonical Content-Type from config (not
  the client's), `X-Content-Type-Options: nosniff`, `CSP: default-src 'none'; sandbox`.
  An HTML file renamed to .pdf/.txt is rejected at upload; a polyglot that passes
  is still never rendered inline on the API origin.
- Validation: extension whitelist AND finfo content type per extension (tests cover
  html, html→pdf, html→txt, text→pdf, zip→pdf, no extension).
- Size: 10 MB in the service (413). Requires PHP/nginx limits ≥ 10 MB in the
  deployment repo; below that, uploads fail earlier with 422/413 from PHP/nginx (fails closed).
- Abuse: 10 uploads per user per day via the throttle middleware; entries and files
  are deleted after 24–48 h; account deletion wipes both disks.
- Accepted residual risks (low, owner-only download, no sharing):
  - any zip archive renamed to .docx/.xlsx/.pptx, and any OLE file renamed to
    .doc/.xls/.ppt, passes (libmagic reports these generically);
  - no malware scanning or macro stripping (out of scope);
  - rejected uploads count toward the daily quota.
- Not in scope, tracked as a follow-up: other features still serve files from the
  public `user_data` disk (library covers, avatars, ...).

## Cross-review (marmaduke, room file marmaduke/api-review.md)

APPROVED. Findings fixed in the follow-up commit: the overdue-job test now asserts
image deletion on `private_user_data`; `GET /clipboard/{id}/image` sends `nosniff` and
the sandbox CSP like `GET /clipboard/{id}/file`; the move migration skips unreadable
files instead of aborting. Noted, not changed: a PHP-level upload failure is a 422,
shown as "type not supported" by the frontend — unreachable once the deployment
limits are ≥ 10 MB.

## Overall

APPROVED — with the caveat that this is a self-review. Before deploy: set PHP
`upload_max_filesize`/`post_max_size` and nginx `client_max_body_size` in the
deployment repo, review both migrations, deploy the API before the web frontend.
An independent `@reviewer`/`@security` pass is recommended if one is available.
