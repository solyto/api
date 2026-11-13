# Implementation: file dav report

id: innocent
developer: developer
date: 2026-11-13

## Summary

Report-only job. The deliverable is [`file-dav-2026-11-13.md`](../../../reports/file-dav-2026-11-13.md). It covers
how Solyto could offer WebDAV file sync, as a native sabre/dav file tree,
a separate WebDAV service, or by pointing to or integrating an external
Nextcloud. It separates the generic-WebDAV client tier from the
Nextcloud-client tier, lists the open product decisions without deciding
them, and gives a phased plan. No production code, routes, config,
migrations or dependencies were changed.

## Changes

TASK-1: Inventoried the DAV stack (report §2): factory, node tree, plugins,
auth, entry points, `dav_data` disk, deletion and export. **Deviation from
the task's stated premise:** `DavController` does not buffer the body in
memory. Sabre already streams the response through `Sapi::sendResponse()`
during `exec()`. The controller's extra `response($resource)` then throws a
`TypeError` on every GET. This was verified with a throwaway probe test
through the HTTP kernel, which was deleted and never committed. The probe
also showed that the `routes/web.php` DAV routes return 405 for PROPFIND
and would hit CSRF on writes, so production most likely uses
`public/dav.php`. That needs confirming in the deployment repo.
TASK-2: Researched sabre/dav 4.7.1 file nodes from the vendor source (§4,
§3): `FSExt`, `DAVACL\FS\HomeCollection` (keyed by email, mode 0777, so not
usable as-is), the default ACL that gives all authenticated users full
access to non-IACL nodes, host-disk quota info, and Locks, PropertyStorage,
PartialUpdate and TemporaryFileFilter. The `locks`/`propertystorage`
tables already exist on `pgsql` but are unused.
TASK-3: Client tiers with sources (§3). Nextcloud-only APIs are marked
documented, partly documented or implementation-defined.
TASK-4: Storage options S-1/S-2/S-3, metadata placement (MariaDB per rule
12.6; `pgsql` lock/property tables flagged), ETags, quotas, export and
deletion (§5).
TASK-5: Security risks R-1–R-9 with mitigations (§6) and operations (§7).
TASK-6: REST/frontend outline against `docs/STANDARDS.md` (§8).
TASK-7: Options A/B/C compared with rough, assumption-labelled estimates
(§4), and a phased plan (§9).
TASK-8: Wrote `report.md` and this file.

Files: `docs/jobs/innocent_file-dav-report/report.md` (new),
`docs/jobs/innocent_file-dav-report/implementation.md`.

`composer test` and `composer analyse` both exit 0. A fresh worktree needed
`composer install` (from the lock file, no new packages) and the gitignored
`storage/framework/*` and `storage/app/{public,private}/user` directories.
Without them, two Export tests fail with "No such file or directory".

## Known issues / follow-ups

- `DavController` throws a `TypeError` after every GET (logged as "DAV
  Factory or Server error" via `public/dav.php`). This is an existing bug
  that is independent of files and is worth a backlog entry. Fixing it
  changes DAV handling, so it needs flagging first.
- The `routes/web.php` DAV routes cannot serve WebDAV methods (405 for
  PROPFIND, CSRF on writes). Check whether they are dead and decide what to
  do with them.
- DAV Basic auth has no rate limiting.
- Export zips are written to the public `user_data` disk; this should be
  reviewed.
- `.env.testing` shows as modified in `git status` but cannot be hashed by
  git ("unsupported file type", probably a mount). It was left untouched.
