# Tasks: file dav report

id: innocent
analyst: analyst
date: 2026-11-13

## Scope

This is a **report-only** job. No production code, migrations, routes, config
or dependencies change. The only deliverable is one Markdown report in this
job directory: `docs/jobs/innocent_file-dav-report/report.md`. Do not edit
`app/Dav/`, `routes/web.php`, `config/filesystems.php`, the `pgsql`
connection or `composer.json`. All of these are only read and cited.

The report answers: "How could Solyto offer WebDAV file sync like Nextcloud
Files, and what would it take?" Every claim about the current codebase must
cite `file:line`. Every claim about external software (sabre/dav, clients,
Nextcloud) must link a source.

## Unclear / assumptions (state these in the report, don't resolve them)

- The brief leaves "Why" and "Out of scope" empty. The report should list the
  product decisions a human has to make: quotas, sharing, public links,
  versioning, trash, E2E encryption, web UI in the separate frontend repo,
  and whether the self-hosted and solyto.app deployments differ. It must
  not decide them.
- "Like Nextcloud" can mean plain WebDAV access (Finder, Explorer, davfs2,
  rclone, Cyberduck) or the Nextcloud desktop/mobile sync clients, which
  need Nextcloud-specific endpoints (`status.php`, `ocs/`, `remote.php/dav`,
  chunked upload v2, `oc:`/`nc:` properties). The report must treat these as
  separate tiers and not assume the second one.

## Task breakdown

TASK-1: Inventory the current DAV stack and record the facts the report builds on: the server factory, node tree, plugins, auth backend, controller request/response handling, routes and the `dav_data` disk.
files: app/Dav/Factories/DavServerFactory.php, app/Dav/Controllers/DavController.php, app/Dav/Auth/LaravelAuthBackend.php, app/Dav/Plugins/*.php, routes/web.php, config/filesystems.php, composer.json/composer.lock (sabre/dav version), docs/backlog/dav-unhandled-post.md (read only)
depends: none
risk: low — read-only; the findings must include these known constraints: `DavController` buffers the full Sabre response body in memory (`getBody()` into `response()`), so large downloads/uploads won't stream. DAV auth is HTTP Basic with the account's main password (no app passwords). All nodes hang off one root at base URI `/`.

TASK-2: Research how sabre/dav serves files (`Sabre\DAV\FS` / `FSExt` directories, custom `ICollection`/`IFile` nodes, `Locks\Plugin` + backend, `PartialUpdate`, `PropertyStorage`, `TemporaryFileFilter`, quota properties) and pick a node design that fits Solyto's per-user isolation.
files: none changed (sources: sabre.io docs, vendor/sabre/dav source)
depends: TASK-1
risk: low — research only. Check the installed sabre/dav version in vendor rather than assuming one from docs.

TASK-3: Research client compatibility tiers: generic WebDAV clients (Windows Explorer, macOS Finder, GNOME/KDE, iOS Files via third-party apps, rclone, Cyberduck, davfs2) versus Nextcloud/ownCloud sync clients, and what each tier needs from the server (LOCK, ETags, `getlastmodified`, quota, chunked upload, OCS endpoints, login flow v2).
files: none changed
depends: none
risk: medium — external client behaviour changes over time and is easy to misstate, so cite current sources for every requirement. The report should mark which Nextcloud APIs are undocumented or unstable.

TASK-4: Design the storage and metadata model options: file blobs on a Laravel disk (`dav_data`/local vs S3) versus a DB-backed node table, where metadata lives (default MariaDB vs the `pgsql` DAV connection), ETag/sync-token strategy, quotas, and how the existing user export and account deletion would have to include files.
files: none changed; cite config/filesystems.php, app/Api/Export/**, app/Api/Users/Services/UserService.php (deletion), app/Dav/Models/* (read only)
depends: TASK-1, TASK-2
risk: medium — any option touching the `pgsql` connection/schema or the DAV node tree must be flagged explicitly per the hard rules, and the report must say so.

TASK-5: Assess the integration and operational impact: mounting a `files/` node next to `calendars/`/`addressbooks/`/`principals/` in `DavServerFactory`, streaming through Laravel (or offloading to the reverse proxy / X-Sendfile), PHP and proxy upload/body limits and timeouts, the Redis/queue impact, backups, malware/abuse concerns, and the security model (app passwords or token auth instead of the main password, tenant isolation, path traversal).
files: none changed; cite app/Dav/Factories/DavServerFactory.php, app/Dav/Controllers/DavController.php, app/Dav/Auth/LaravelAuthBackend.php, app/Dav/Tests/DavIsolationTest.php (read only)
depends: TASK-1, TASK-2, TASK-4
risk: medium — security conclusions are easy to overstate. Keep them as identified risks with mitigations, not as guarantees.

TASK-6: Outline the REST API and frontend surface a file feature would need (list/upload/download/move/share endpoints under `/api/v1`, the OpenAPI annotations, the ApiResponse envelope, and the locales for any user-facing strings). Keep it at outline level and list it as a dependency on the separate frontend repo.
files: none changed; cite routes/api.php, app/Api/ApiResponse.php, docs/STANDARDS.md (read only)
depends: TASK-4
risk: low — outline only. It must not prescribe new abstractions beyond those in STANDARDS.md.

TASK-7: Compare the build options with rough effort and risk: (A) a native sabre/dav file tree in the existing server, (B) a separate WebDAV service (e.g. rclone serve webdav, a standalone sabre app or an existing open-source server) behind the same auth, (C) integrating with or recommending an external Nextcloud/other instance. Then give a recommended phased path (MVP → sync-client compatibility).
files: none changed
depends: TASK-2, TASK-3, TASK-4, TASK-5, TASK-6
risk: medium — effort estimates are guesses. Label them as rough and state their assumptions. Any option that needs a new composer/npm dependency must say approval is required.

TASK-8: Write `report.md` in the job directory with these sections: summary, current state, client tiers, architecture options, storage/data model, security, operations, API/frontend, phased plan, open decisions for humans, and sources. Also fill in `implementation.md` to point to it.
files: docs/jobs/innocent_file-dav-report/report.md (new), docs/jobs/innocent_file-dav-report/implementation.md
depends: TASK-1 – TASK-7
risk: low — documentation only. `composer test` and `composer analyse` must still exit 0, which should be unaffected because no code changes.
