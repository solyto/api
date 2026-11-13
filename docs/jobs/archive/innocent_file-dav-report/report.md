# Report: WebDAV file sync for Solyto

id: innocent
date: 2026-11-13
status: research only. No code, routes, config, migrations or dependencies were changed.

Conventions used here:

- `path:line` refers to this worktree. `vendor/…` paths refer to the
  versions pinned in `composer.lock` (sabre/dav **4.7.1**, `composer.lock:4668-4669`,
  which is also the latest sabre/dav release as of 2026-07-07 [S1];
  laravel/framework v12.70.0, `composer.lock:1615-1616`).
- Effort figures are **rough guesses** in developer-days for one developer
  who knows this codebase. Each figure states its assumptions and none of
  them is a commitment.
- **[DAV-FLAG]** marks every step that would change the DAV node tree, its
  plugins, its auth, its routes/entry point or the `pgsql` connection/schema.
  Per the hard rules and `docs/STANDARDS.md:824-834` (rule 12.6), each of
  these must be flagged and approved before it is done.

---

## 1. Summary

- **Plain WebDAV file access is feasible inside the existing DAV server.**
  This covers macOS Finder, Windows Explorer, GNOME/KDE, davfs2, rclone,
  Cyberduck and iOS/Android WebDAV apps. The installed sabre/dav already
  ships every building block: filesystem nodes (`Sabre\DAV\FSExt`), per-user
  ACL-protected homes (`Sabre\DAVACL\FS\HomeCollection`), locking, property
  storage, partial updates and a temporary-file filter. The DAV schema on
  `pgsql` already contains `locks` and `propertystorage` tables that nothing
  uses today (`database/migrations/2025_12_08_161442_create_dav_tables.php:186-198`,
  `:220-228`).
- **Nextcloud desktop/mobile client compatibility is a different and much
  larger project.** It means emulating Nextcloud-specific endpoints
  (`status.php`, OCS capabilities, Login Flow v2, `remote.php/dav`, chunked
  upload v2, `oc:`/`nc:` properties). Parts of this are only defined by
  Nextcloud's own implementation. This report treats it as a separate tier
  and does not recommend starting there (§3, §9).
- **Before files can be added safely, a few things in the current DAV stack
  need fixing first** (§2.3). All of them are [DAV-FLAG]:
  1. `DavController` turns Sabre's stream body into a Laravel response, and
     for stream bodies (every GET) this throws a `TypeError`. The bytes
     have already been sent by then, but the error is logged on every
     download.
  2. The `routes/web.php` DAV routes cannot serve WebDAV methods. PROPFIND
     returns 405 and writes would hit CSRF. Production most likely uses
     `public/dav.php` instead, but that is decided in the deployment repo.
  3. DAV auth is HTTP Basic with the main account password and has no
     throttling. A file mount stores that password on every device.
  4. Any node that does not implement `IACL` gets sabre's default ACL,
     which gives **every authenticated user full access**.
- **Recommended path (§9):** first an MVP of a native `files/` tree for
  generic clients (option A), stored on the existing but unused `dav_data`
  disk, with app passwords, quotas and owner-only ACLs. After that, a REST
  API for the web frontend. Nextcloud-client compatibility only if there is
  demand, and only after a separate spike.
- Many product decisions are open (quotas, sharing, public links,
  versioning, trash, E2E encryption, self-hosted vs solyto.app). They are
  listed in §10 and not decided here.

---

## 2. Current state

### 2.1 The DAV server

| Fact | Where |
|---|---|
| One `Sabre\DAV\Server`, built per request by `DavServerFactory::createServer()` | `app/Dav/Factories/DavServerFactory.php:31-61` |
| All DAV data uses the `pgsql` connection's PDO | `app/Dav/Factories/DavServerFactory.php:33-36` |
| Root has three nodes: `principals/`, `calendars/`, `addressbooks/` | `app/Dav/Factories/DavServerFactory.php:38-42` |
| Base URI is `/`, so a `files/` node would sit next to them | `app/Dav/Factories/DavServerFactory.php:45` |
| Plugins: Auth, ACL (`hideNodesFromListings = true`), Browser, CalDAV, Privacy, CardDAV, Sync, Sharing, CalDAV Sharing, CacheInvalidation | `app/Dav/Factories/DavServerFactory.php:46-58` |
| No Locks, PropertyStorage, PartialUpdate or TemporaryFileFilter plugin is registered | same lines; `grep -rn "Locks\|PropertyStorage\|PartialUpdate\|TemporaryFile" app` finds nothing |
| Factory is a container singleton | `app/Shared/Providers/DavServiceProvider.php:13-18` |
| `CacheInvalidationPlugin` hooks `afterCreateFile`, `afterWriteContent`, `afterUnbind`, `afterBind`, `afterMethod:PROPPATCH` for all paths, but acts only on `calendars/…` and `addressbooks/…` | `app/Dav/Plugins/CacheInvalidationPlugin.php:24-31`, `:38-102` |
| `PrivacyPlugin` intercepts free-busy and principal-property-search reports | `app/Dav/Plugins/PrivacyPlugin.php:20-50` |
| Principal URIs are `principals/<email>` | `app/Dav/Services/Principals.php:29`, `:49-51` |
| DAV log: daily file `logs/dav.log`, level `warning` by default | `config/logging.php:96-102` |

### 2.2 Auth

`LaravelAuthBackend` extends `AbstractBasic`. It looks the user up by email,
checks the password hash and sets the Laravel guard user
(`app/Dav/Auth/LaravelAuthBackend.php:11-23`). This means:

- the credential is the **main account password**. No app passwords or
  tokens are accepted;
- there is **no rate limit** on failed DAV logins. `grep -rn
  "RateLimiter\|throttle" app/Dav` finds nothing, while the REST login
  routes are throttled (`routes/api.php:81-89`);
- the tests cover challenge, wrong password, unknown user and success
  (`app/Dav/Tests/DavAuthTest.php:9-49`).

### 2.3 Request/response handling: what actually happens

The brief and tasks expected `DavController` to buffer the full Sabre body
in memory. **Reading the code and running a throwaway probe test (since
deleted) shows something different:**

1. `DavController::handle()` calls `$server->exec()`
   (`app/Dav/Controllers/DavController.php:15-21`). Sabre's
   `Server::start()` → `invokeMethod(…, $sendResponse = true)` sends the
   response itself through `Sapi::sendResponse()`, i.e. `header()` plus
   `stream_copy_to_stream()` to `php://output` in 4 MiB chunks
   (`vendor/sabre/dav/lib/DAV/Server.php:452-491`, error path `:310`;
   `vendor/sabre/http/lib/Sapi.php:62-133`). **So downloads already stream.**
   Uploads also stream: `Sapi::getRequest()` uses `php://input` as the
   body stream (`vendor/sabre/http/lib/Sapi.php:51`).
2. After that, the controller builds a second, Laravel response from
   `$response->getBody()` (`DavController.php:23-27`). For GET, Sabre always
   turns the body into a stream resource, even for string content
   (`vendor/sabre/dav/lib/DAV/CorePlugin.php:85-93`). Symfony's
   `Response::setContent(?string)` rejects a resource
   (`vendor/symfony/http-foundation/Response.php:426`), so this line throws
   a `TypeError`. The probe reproduced it: Sabre echoed the 208-byte `.ics`,
   then Laravel produced a 500 with `TypeError … setContent(): Argument #1
   ($content) must be of type ?string, resource given`.
3. `public/dav.php` is a separate front controller. It boots Laravel
   without the HTTP kernel, calls the controller, catches any `Throwable`
   and logs it as "DAV Factory or Server error". The Laravel response is
   never sent, because `$response->send()` is commented out
   (`public/dav.php:22-38`). If production routes DAV traffic here, the
   client gets the correct bytes, but **every GET logs an error**. Today
   that is rare, because CalDAV/CardDAV clients mostly use REPORT. For file
   sync it would affect every download.
4. The `routes/web.php` DAV routes (`routes/web.php:10-11`) go through the
   HTTP kernel and the `web` middleware group. `Route::any` does not
   include WebDAV verbs: the probe got **405 for PROPFIND** from the router.
   `ValidateCsrfToken` exempts only `HEAD/GET/OPTIONS`
   (`vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/VerifyCsrfToken.php:103-106`)
   and `webhooks/*` (`bootstrap/app.php:31-33`), so PUT/DELETE would fail
   CSRF outside tests. The web group also starts a session on every
   request.
5. The DAV tests call the Sabre server directly with a silent SAPI, so they
   bypass both entry points (`tests/Pest.php:115-135`). Neither the
   double-response issue nor the 405 is covered by tests.

**Conclusion:** production DAV almost certainly runs through
`public/dav.php` behind the reverse proxy (for `dav.solyto.de`). This repo
cannot confirm that. It is configured in `solyto/deployment` and must be
checked there before any file work starts (§10, item O-1). Fixing
`DavController` so it no longer re-wraps the body is a prerequisite
[DAV-FLAG] (§9, phase 0). It also belongs next to the existing log-noise
backlog (`docs/backlog/dav-unhandled-post.md`).

### 2.4 Storage and data lifecycle

| Fact | Where |
|---|---|
| A `dav_data` local disk exists (`storage/app/private/dav`, private) but **nothing uses it** | `config/filesystems.php:56-61`; `grep -rn dav_data app` → 0 hits |
| Per-user files elsewhere live on `user_data` (public, has URL) and `private_user_data`, keyed by user UUID | `config/filesystems.php:41-54`; e.g. `app/Api/Users/Services/UserService.php:142-149` |
| An `s3` disk is configured from env but not used | `config/filesystems.php:72-80` |
| Account deletion: refuses to run without a UUID, deletes DAV data, app rows, then the two per-user directories | `app/Api/Users/Services/UserService.php:98-154` (guard `:101-104`, DAV `:106`, dirs `:142-149`) |
| DAV deletion is per principal URI | `app/Dav/Services/DavService.php:59-75` |
| Export: fifteen per-module exporters write into a temp dir, which is zipped onto the **`user_data` (public) disk** | `app/Api/Export/Services/ExportService.php:35-66`, `:69-96` |
| Export download is served by a controller (`response()->download`) | `app/Api/Export/Controllers/ExportController.php:91-118`, route `routes/api.php:447-450` |

---

## 3. Client compatibility tiers

### Tier 1: generic WebDAV clients (RFC 4918 [S2])

| Client | What it needs from the server | Source |
|---|---|---|
| macOS Finder | **Class 2 (LOCK)**, otherwise it mounts read-only. Uploads with `Transfer-Encoding: chunked` (no `Content-Length`) | [S3] |
| Windows Explorer (WebClient) | Basic auth is off by default for plain HTTP and needs HTTPS or a registry change. File size is limited to 50,000,000 bytes by default (`FileSizeLimitInBytes`, max 4 GB). Expects LOCK. Shows quota only if `quota-*` properties are correct | [S4], [S5] |
| davfs2 (Linux) | Class 2, otherwise read-only unless `nolocks` is set. Caches heavily | [S6] |
| GNOME (gvfs) / KDE (kio) | Standard PROPFIND/GET/PUT/MOVE/COPY/DELETE/MKCOL; ETag + `getlastmodified` | [S2] |
| rclone (`vendor = other`) | Standard verbs. Uses `getlastmodified` and size for change detection. Vendor-specific extras (chunking, checksums) only for `nextcloud`/`owncloud` vendors | [S7] |
| Cyberduck / Mountain Duck | Standard WebDAV; LOCK optional | [S8] |
| iOS Files / Android | Usually reached through third-party WebDAV apps that need the same verbs as above. **Not verified against a source here, check before promising anything** | n/a |

What tier 1 needs from Solyto:

- **Required:** PROPFIND (`resourcetype`, `getcontentlength`,
  `getlastmodified`, `getetag`, `getcontenttype`), GET with Range, PUT,
  DELETE, MKCOL, MOVE, COPY. sabre's core plugin provides all of these on
  top of `ICollection`/`IFile` nodes.
- **Required for Finder/Windows/davfs2 write access:** `Sabre\DAV\Locks\Plugin`
  with a backend [S9]. The PDO backend's `locks` table already exists on
  `pgsql` (`…create_dav_tables.php:186-198`) [DAV-FLAG].
- **Strongly recommended:** `TemporaryFileFilterPlugin` [S10]. It stops
  macOS `._*`/`.DS_Store` and Office lock/temp files from landing in the
  user's storage (`vendor/sabre/dav/lib/DAV/TemporaryFileFilterPlugin.php`).
  Its default data dir is under `session.save_path` (`:77-85`), so a
  per-user directory on a private disk would have to be passed in.
- **Recommended:** RFC 4331 quota properties [S11]. sabre answers them for
  `IQuota` nodes (`vendor/sabre/dav/lib/DAV/CorePlugin.php:768-775`).
- **Optional:** PropertyStorage (dead properties such as Windows
  `Win32*` timestamps [S4]; `propertystorage` table exists, `:220-228`)
  [DAV-FLAG], and PartialUpdate (`PATCH` with `X-Update-Range`, sabre-specific
  [S12]; `FSExt\File` implements it, `vendor/sabre/dav/lib/DAV/FSExt/File.php:17`, `:64`).

### Tier 2: Nextcloud / ownCloud sync clients

These clients are built for a Nextcloud server, not for WebDAV in general.
Based on Nextcloud's own developer docs, a compatible server would need at
least:

| Requirement | Source | Stability |
|---|---|---|
| WebDAV under `/remote.php/dav/files/{user}/…` | [S13] | documented |
| `oc:`/`nc:` properties (`oc:permissions`, `oc:fileid`, `oc:size`, …) | [S13] | partly documented. The full set the desktop client asks for is defined by the client source [S16] |
| Chunked upload v2: `MKCOL /remote.php/dav/uploads/{user}/{id}`, numbered chunks 1–10000 of 5 MB–5 GB, final `MOVE` to `.file` with `Destination` header, 24 h expiry | [S14] | documented |
| Login Flow v2: `POST /index.php/login/v2`, browser login, poll endpoint, returns an app password | [S15] | documented |
| OCS API (`/ocs/v2.php/…`, capabilities, user info, quota) | [S17] | documented in parts. Capabilities that the client actually relies on are **implementation-defined** |
| `status.php` (server/version detection) | not in Nextcloud's developer manual; known from client behaviour/source [S16] | **undocumented / unstable**: the client may refuse servers whose reported version is too old |
| Folder ETags that change when anything below changes (so the client can skip unchanged subtrees) | implied by the sync model; not specified for third-party servers | **implementation-defined** |
| Remote wipe, E2E encryption, notifications, activity | [S18] | optional, Nextcloud-specific |

Assessment: emulating tier 2 means following a moving target that Nextcloud
controls. It needs reverse-engineering of the desktop/mobile clients
(GPL [S16]) and continuous testing against new client releases. The
practical alternative for "sync" is to point users at clients that sync
over plain WebDAV: rclone `bisync`/`sync` [S7], and on Windows/macOS tools
such as Mountain Duck (commercial) [S8]. This trade-off needs a human
decision (§10, D-2).

---

## 4. Architecture options

### Option A: native file tree in the existing sabre server

Add a `files/` root node next to `principals/`, `calendars/`,
`addressbooks/` (`DavServerFactory.php:38-42`) [DAV-FLAG], plus the Locks
(and optionally TemporaryFileFilter, PropertyStorage, PartialUpdate)
plugins.

Node design (sabre has the needed pieces, [S19], [S20]):

- sabre ships `Sabre\DAVACL\FS\HomeCollection`. It is a principal-driven
  collection that gives every principal a home directory with an
  **owner-only** ACL (`vendor/sabre/dav/lib/DAVACL/FS/HomeCollection.php:75-99`),
  built on `DAVACL\FS\Collection`/`File`, which extend `FSExt\Directory`/
  `FSExt\File` and implement `IACL` (`vendor/sabre/dav/lib/DAVACL/FS/Collection.php:9`, `:20`;
  `FS/File.php:7`, `:18`).
- **It should not be used as-is:** it names the directory after the
  principal basename, which is the user's **email** here
  (`HomeCollection.php:86-88`; `Principals.php:29`), and creates it with mode `0777`
  (`HomeCollection.php:90-92`). A small Solyto subclass in `app/Dav/` would
  resolve the principal to the user's UUID (the same key the other per-user
  disks use, `UserService.php:142-149`), create the directory with
  restrictive permissions, and return owner-ACL nodes. URLs could stay
  `files/<email>/…` for symmetry with `calendars/<email>/` or become
  `files/` (current user only). That choice is open (§10, D-9).
- `FSExt\Directory::getQuotaInfo()` reports the **host disk's** used/free
  space (`vendor/sabre/dav/lib/DAV/FSExt/Directory.php:163-172`). That is
  wrong for per-user quotas and leaks host details, so it must be
  overridden.
- **Every file node must implement `IACL`.** For any node that does not,
  `DAVACL\Plugin::getAcl()` falls back to the default ACL
  (`vendor/sabre/dav/lib/DAVACL/Plugin.php:566-573`), which is
  `{DAV:}authenticated` → `{DAV:}all` (`:299-305`). In other words, any
  logged-in Solyto user could read and write the files.

Pros: one server, one auth, one URL; reuses the existing ACL/principal
model and the test harness (`tests/Pest.php:115-135`); no new
dependency. Cons: every file byte goes through PHP-FPM workers (§7); the
DAV entry-point issues (§2.3) must be fixed first; it touches the DAV tree
and plugins (all [DAV-FLAG]).

Rough effort (MVP, local disk, tier 1, owner-only, quotas, app passwords,
tests): **10–15 days.** Assumptions: FSExt-based nodes on `dav_data`, no
sharing/versioning/trash, no REST API yet, one person who already knows the
DAV stack, deployment changes done by whoever owns `solyto/deployment`.

### Option B: separate WebDAV service behind Solyto auth

Run a dedicated WebDAV server next to the API, for example:

- `rclone serve webdav` [S21], which supports `--auth-proxy` (an external
  program authenticates and returns a per-user backend) and
  `--user-from-header` behind a reverse proxy;
- a small Go server such as `hacdias/webdav` [S22];
- a standalone sabre/dav app (a second PHP entry point sharing the same
  library).

Auth would be delegated to Solyto, for example through an auth-proxy hook or
reverse-proxy `auth_request` that validates a Solyto app password against a
new internal endpoint.

Pros: file I/O is off the PHP-FPM pool, and Go servers stream well; the
existing CalDAV/CardDAV server stays untouched. Cons: a new service to
deploy, monitor, back up and patch (ops lives in `solyto/deployment`);
quotas, deletion, export and the REST API still need Solyto-side code that
reads the same storage; two DAV URLs for users; for self-hosters, another
container. Any new binary/image or library **requires owner approval**.

Rough effort: **8–12 days** for the auth bridge, deployment, deletion/export
integration and tests, plus ongoing ops. Assumptions: an off-the-shelf
server with per-user root support (rclone's auth proxy), and no LOCK
requirement beyond what that server provides (to verify per server).

### Option C: integrate with or recommend an external Nextcloud (or similar)

- **C1, recommend only:** document that users who want file sync should run
  or use a Nextcloud/other instance. No code. This gives full tier 2 with no
  emulation, but files stay outside Solyto (no export, no deletion, no
  integration).
- **C2, integrate:** store per-user credentials for an external WebDAV
  server and proxy or link to it from Solyto. This adds credential storage
  (secrets hygiene), a cross-service failure mode, and makes Solyto
  responsible for another provider's availability.

Rough effort: C1 **≤ 1 day** (docs). C2 **10+ days**, and it raises its own
product and privacy questions.

### Comparison

| | A native | B separate service | C1 recommend | C2 integrate |
|---|---|---|---|---|
| Tier 1 clients | yes | yes | n/a (external) | n/a (external) |
| Tier 2 (Nextcloud clients) | only with large emulation work | only with large emulation work | yes (real Nextcloud) | yes (real Nextcloud) |
| New dependency / service | no | **yes (approval)** | no | maybe |
| Touches DAV tree / pgsql | **yes [DAV-FLAG]** | no (or only auth) | no | no |
| PHP-FPM load from file I/O | yes | no | no | no |
| Fits "free, private, all-in-one" | best | good | weak | medium |
| Rough effort | 10–15 d | 8–12 d + ops | ≤ 1 d | 10+ d |

---

## 5. Storage and data model

### 5.1 Where the bytes live

| Option | How | Pros | Cons |
|---|---|---|---|
| **S-1 local disk `dav_data`** | FSExt-style nodes on `storage/app/private/dav/<uuid>/…` (`config/filesystems.php:56-61`) | already configured; sabre streams files natively; atomic `rename()` for MOVE (`FSExt/Directory.php:195-207`); simplest | bound to one host's volume; ETags come from inode/size/mtime (`FSExt/Directory.php:54-58`) and change if data is restored or copied; no stable file IDs; listing large folders hits the filesystem |
| **S-2 Laravel disk via Flysystem (local or S3)** | custom `IFile`/`ICollection` nodes calling `readStream`/`writeStream` [S23] | storage backend can be switched by config; S3 for scale | S3 has no real directories or atomic rename: MOVE/rename of a folder is copy+delete per object; needs a metadata table anyway for ETags, mtime and listings; S3 credentials via `DockerSecretHelper` (currently `env`, `config/filesystems.php:72-80`) |
| **S-3 metadata table + blob store** | a DB table holds the tree (`id`, `user_id`, `parent_id`, `name`, `size`, `mime`, `etag`, `mtime`, `blob_key`); blobs on S-1 or S-2 keyed by id/hash | stable file IDs (`oc:fileid`-style), cheap renames/moves (one row), folder-ETag propagation and RFC 6578 sync tokens [S24], fast quota sums, enables trash/versioning/sharing later, REST API reads the same table | the most work; tree must stay consistent with blobs (orphan cleanup job) |

Recommendation for the MVP: **S-1**, as long as the decisions in §10 do not
require versioning/trash/sharing at launch. Move to **S-3** as soon as any
of those, or tier 2, is wanted. S-3's metadata model is also what a REST
file API (§8) would want.

### 5.2 Where metadata lives

- Rule 12.6 puts module data on the default (MariaDB) connection and
  reserves `pgsql` for DAV. Only `app/Dav` writes to it
  (`docs/STANDARDS.md:824-834`, rule 5.5 at `:528`). A REST `Files` module
  under `app/Api/` would need to read the tree, so an S-3 node table
  belongs on **MariaDB**, and the DAV nodes in `app/Dav/` read it through
  Eloquent models like the rest of the app.
- Locks and dead properties are pure DAV protocol state. The existing
  `locks` and `propertystorage` tables on `pgsql` fit sabre's PDO backends
  directly (`…create_dav_tables.php:186-198`, `:220-228`). Using them changes
  DAV behaviour → **[DAV-FLAG]**. The migration is driver-aware so tests
  keep working on SQLite (`docs/STANDARDS.md:826-829`).
- Lock and property rows are keyed by URI. If file URLs contain the email,
  an email change would orphan them. No email-change path exists today,
  but keying storage by UUID (§4) keeps this safe.

### 5.3 ETags and change detection

- S-1: `FSExt\File::getETag()` = sha1(inode, size, mtime)
  (`vendor/sabre/dav/lib/DAV/FSExt/File.php:121-128`). That is enough for
  tier 1. Collections have no ETag, so a client must walk the tree to find
  changes.
- S-3: store an ETag per node and bump all ancestors on every write (what
  Nextcloud-style clients expect for efficient sync). Optionally implement
  `Sabre\DAV\Sync\ISyncCollection` per user root so the already-registered
  Sync plugin (`DavServerFactory.php:55`) can answer `sync-collection`
  reports [S24].

### 5.4 Quotas

- Expose `quota-used-bytes`/`quota-available-bytes` [S11] via a
  user-specific `IQuota::getQuotaInfo()` (§4).
- Enforce on `beforeCreateFile`/`beforeWriteContent`. `Content-Length` is
  not reliable: Finder uploads with chunked transfer encoding [S3]. The
  node's write must therefore count bytes while streaming and abort (and
  clean up) when the user would go over quota.
- With S-1, "used" means summing file sizes per user, which is slow on large
  trees. Keep a running counter (MariaDB column, adjusted on
  write/delete/move) or recompute in a queued job.

### 5.5 Export and account deletion

- **Deletion** (`UserService::destroy`, `app/Api/Users/Services/UserService.php:98-154`):
  must also delete the user's file directory (same UUID guard as `:101-104`,
  same pattern as `:142-149`), the lock and property rows for the user's
  file URIs on `pgsql` [DAV-FLAG], and any S-3 rows. With S-3 + S3, it must
  also delete blobs (possibly via a queued job, given large trees).
- **Export** (`ExportService::export`, `app/Api/Export/Services/ExportService.php:35-66`):
  adding a `files/` folder to the zip could multiply its size by orders of
  magnitude. The zip also lands on `user_data`, which is configured as a
  **public** disk with a URL (`config/filesystems.php:41-47`,
  `ExportService.php:76-78`), even though downloads go through the
  controller (`ExportController.php:91-118`). Options: include files, export
  files separately, or rely on the WebDAV mount as the export path. This is
  a decision (§10, D-7). It also needs a check of whether exports belong on
  a private disk at all, which is outside the scope of this job.

---

## 6. Security

These are identified risks with possible mitigations, not guarantees.

| # | Risk | Evidence | Mitigation |
|---|---|---|---|
| R-1 | Main password stored on every mounted device; leaked once = full account (incl. REST API) | `LaravelAuthBackend.php:11-23` | **App passwords**: per-device, revocable, DAV-only. Sanctum personal access tokens already exist with list/revoke endpoints (`routes/api.php:99-100`); a token with a `dav` ability could be accepted as the Basic password. This changes DAV auth for calendars/contacts too [DAV-FLAG]. The UI for creating them lives in the frontend repo |
| R-2 | Online password guessing over DAV, no throttle | §2.2 | Rate limit failed DAV auth per email + IP (Laravel `RateLimiter` inside the auth backend) [DAV-FLAG]. Also mitigated by R-1 |
| R-3 | Cross-tenant access through non-IACL nodes | `DAVACL/Plugin.php:299-305`, `:566-573` | Every node implements `IACL` with an owner-only ACL. Extend `app/Dav/Tests/DavIsolationTest.php` (the existing cross-user GET/PROPFIND checks for calendars/cards) with `files/` cases for GET, PUT, MOVE/COPY with a foreign `Destination`, DELETE, LOCK, PROPFIND depth 1 |
| R-4 | Path traversal / escaping the user root | sabre splits paths on `/`; FSExt rejects `.`/`..` (`FSExt/Directory.php:46-49`, `:96-98`, `:115-117`) | Keep the user root outside any shared path; `realpath()` containment check in the custom nodes; never create symlinks (WebDAV cannot, but `FilesystemIterator`/`file_exists` follow existing ones, `:128-142`); reject NUL/control characters and over-long names |
| R-5 | Stored XSS / phishing via served files | `Browser\Plugin` is enabled (`DavServerFactory.php:51`) and serves files inline | For `files/` GETs send `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, and `Content-Disposition: attachment` for active types (HTML/SVG/XML). Consider not enabling the Browser plugin's actions on `files/`. Serve from the separate DAV origin only |
| R-6 | Abuse of hosting (malware, illegal content), worse with public links | product | Quotas (§5.4); no public links in MVP; abuse contact/takedown process; optional AV scanning (ClamAV would be a **new service needing approval**) |
| R-7 | Large-request DoS (slow uploads tie up FPM workers) | §7 | Proxy body/timeout limits, per-user concurrency limits, separate FPM pool for DAV |
| R-8 | Privacy in logs | `config/logging.php:96-102` | Don't log file names/paths above debug level; the DAV log keeps 14 days |
| R-9 | At-rest confidentiality | product | Disk/volume encryption is an ops choice; server-side or E2E encryption is a decision (§10, D-6). E2E rules out server-side preview/search |

---

## 7. Operations

- **Entry point** [DAV-FLAG]: confirm in `solyto/deployment` which entry point
  serves `dav.solyto.de` (§2.3). Fix `DavController` so it does not re-wrap
  the Sabre body (return nothing, or a response that is known not to be
  sent), and decide what to do with the unusable `routes/web.php` DAV routes.
- **Streaming:** sabre streams in both directions (§2.3). Its docs require
  output buffering to be off for large files [S25]. Verify `output_buffering`
  in the production PHP config. Optional optimisation for downloads on
  local disk: hand off to nginx via `X-Accel-Redirect` [S26]. This bypasses
  sabre's Range handling, so it is a later step.
- **Limits** (deployment repo): nginx `client_max_body_size` (default 1 MB),
  `client_body_timeout`, `fastcgi_read_timeout`, and request buffering
  (`fastcgi_request_buffering` defaults to on, so the proxy spools the whole
  upload to its own temp disk first) [S27]. PHP `max_execution_time` and
  FPM `request_terminate_timeout` [S28]. Nextcloud's big-file guide lists the
  same knobs [S29]. Windows' 50 MB client default is outside our control
  [S5].
- **FPM capacity:** each in-flight transfer holds a worker for its whole
  duration. Consider a separate pool (or Option B) so file traffic cannot
  starve the REST API.
- **Redis/queue:** the MVP needs no queue work per request.
  `CacheInvalidationPlugin` fires for `files/` paths but ignores them
  (`CacheInvalidationPlugin.php:38-102`), at the cost of one path split per
  event. Queued work would be: quota recompute, orphan/temp cleanup,
  trash/version purges (scheduled in `routes/console.php`, rule 12.7,
  `docs/STANDARDS.md:835`), and optional thumbnails/AV scans.
- **Backups:** user files become the largest data set by far. The backup
  plan (volume snapshots or object-store replication), restore testing, and
  the effect on ETags after a restore (S-1 ETags depend on inode/mtime,
  §5.3) must be settled in the deployment repo.
- **Capacity:** monitor total disk usage and per-user usage; alert before
  the volume fills (a full disk breaks the whole app, not only files).
- **Self-hosted vs solyto.app:** self-hosters may want no quota and a
  bind-mounted directory, while solyto.app needs quotas and abuse handling.
  Config-driven limits cover both (§10, D-8).

---

## 8. REST API and frontend surface (outline)

A web UI needs a REST API alongside WebDAV. Outline only, following
`docs/STANDARDS.md`:

- **Module** `app/Api/Files/` with Controllers, Requests, Resources,
  Services, Models, Tests (rule 12.1, `docs/STANDARDS.md:770`). If the DAV
  nodes and the REST service share logic (quota, path rules), the shared
  part follows rule 12.8 (`:841`).
- **Routes** in `routes/api.php` inside the `auth:sanctum` `v1` group, in a
  `Route::prefix('files')->name('files.')` block, custom actions before the
  resource line (rule 1.4, `docs/STANDARDS.md:147-170`; rule 12.2, `:786`).
- **Endpoints** (sketch):
  - `GET files?path=` (list a folder; paginated via `ApiResponse` meta)
  - `POST files/folders` (create folder)
  - `POST files/upload` (multipart; size limits as in existing upload
    requests, e.g. `app/Api/Contacts/Requests/AddContactPhotoRequest.php:12`)
  - `GET files/{file}/download` (binary; like `ExportController.php:113-117`
    and `ClipboardController.php:183`, it bypasses the JSON envelope)
  - `PATCH files/{file}` (rename/move), `DELETE files/{file}` (200 with
    `data: null`, Decisions in `docs/STANDARDS.md`)
  - `GET files/quota`
  - later, if decided: `files/{file}/shares`, `files/trash`, `files/{file}/versions`
- **Every JSON body via `ApiResponse`** (`app/Api/ApiResponse.php:12-79`; rule 7.1,
  `docs/STANDARDS.md:566`). FormRequests for every input (rule 3.1, `:296`).
  Ownership via `scopeForUser`/`HandlesApiAuth` (rules 5.2, 12.3, `:502`, `:796`).
- **OpenAPI** annotations per rules 2.1–2.6 (`docs/STANDARDS.md:191-292`),
  `operationId`s like `filesIndex`, `filesUpload`.
- **Old frontends:** the feature is new data on new endpoints, so it is opt-in
  by construction (rule 13.3, `docs/STANDARDS.md:951`). If it adds a
  navigation entry, check rule 12.11 (`:872`).
- **Strings:** envelope messages stay English (Decisions). Any user-facing
  notification/mail (e.g. "quota almost full") is written in `en` and
  handed to the translator agent (rule 12.10, `:856`; `docs/I18N.md`).
- **Frontend repo dependency:** file browser, upload with progress, app
  password management (R-1), WebDAV connection instructions, and quota
  display all live in the separate solyto frontend repository and need
  their own job there.

---

## 9. Recommended phased path

Each phase is a separate job with its own approval. Estimates are rough
(see conventions at the top).

| Phase | Content | Flags | Rough effort |
|---|---|---|---|
| **0. Groundwork** | Confirm the production entry point (O-1); fix `DavController` re-wrapping (§2.3); decide the fate of the `web.php` DAV routes; DAV auth throttling (R-2); add an entry-point-level test | [DAV-FLAG] | 2–3 d |
| **1. App passwords** | DAV accepts revocable per-device tokens (R-1); REST endpoints to manage them; frontend UI (other repo) | [DAV-FLAG] | 3–4 d (API) |
| **2. MVP: tier 1 file tree** (option A, S-1) | `files/` root node with UUID-keyed, owner-only ACL homes; Locks plugin on the existing `locks` table; TemporaryFileFilter; per-user quota with streaming enforcement; deletion integration; isolation + protocol tests; deployment limits; client smoke tests (Finder, Windows, davfs2, rclone, Cyberduck) | [DAV-FLAG] incl. `pgsql` use | 10–15 d |
| **3. REST API + web UI** | §8 endpoints; frontend job | none on DAV if it reads the same storage | 5–8 d (API) |
| **4. Metadata model** (S-3) | node table on MariaDB, stable IDs, folder ETags / sync tokens, migration of S-1 data; prerequisite for trash, versions, sharing | [DAV-FLAG] | 8–12 d |
| **5. Optional features** | trash, versioning, sharing between friends, public links: each one its own decision and job | depends | per feature |
| **6. Optional tier 2 spike** | time-boxed spike: can the Nextcloud desktop client sync against a Solyto emulation layer (`status.php`, capabilities, Login Flow v2, chunking v2)? Go/no-go afterwards | [DAV-FLAG] | 3–5 d spike; full build unknown, likely weeks |

If the owner prefers to keep file I/O off PHP entirely, phase 2 can be
replaced by option B. Phases 0–1 are still useful, because app passwords
and throttling improve calendar/contact security on their own.

---

## 10. Open decisions for humans

Operational checks (answerable from the deployment repo):

- **O-1** Which entry point serves `dav.solyto.de` and `/dav/` in
  production, and what are the nginx/PHP body-size, buffering and timeout
  settings? (§2.3, §7)
- **O-2** Is `output_buffering` off for the DAV entry point? (§7)

Product decisions (not decided in this report):

- **D-1** Is this wanted at all, and why? The brief leaves *Why* and *Out of
  scope* empty.
- **D-2** Tier 1 only (generic WebDAV clients + rclone for sync), or is
  Nextcloud-client compatibility a requirement? (§3)
- **D-3** Option A, B or C? (§4)
- **D-4** Quotas: default size, per plan, configurable for self-hosters?
  Maximum single-file size?
- **D-5** Sharing between users (friends only, like calendars, Decisions in
  `docs/STANDARDS.md`)? Public links? Both raise abuse questions (R-6).
- **D-6** Versioning, trash/retention, server-side or end-to-end encryption.
- **D-7** Are files part of the data export, or is the WebDAV mount the
  export path? (§5.5)
- **D-8** Do self-hosted and solyto.app deployments differ (quota, AV
  scanning, storage backend)?
- **D-9** URL shape: `files/<email>/…` (symmetric with calendars) or
  `files/…` (current user only)? (§4)
- **D-10** App passwords only for DAV (main password disabled for DAV), or
  both during a transition? This affects existing CalDAV/CardDAV users.
- **D-11** Web UI scope and priority in the frontend repo.

---

## 11. Sources

External:

- [S1] sabre/dav releases (4.7.1, 2026-07-07): https://github.com/sabre-io/dav/releases
- [S2] RFC 4918, HTTP Extensions for WebDAV: https://datatracker.ietf.org/doc/html/rfc4918
- [S3] sabre/dav, Finder client notes (locking required, chunked encoding): https://sabre.io/dav/clients/finder/
- [S4] sabre/dav, Windows client notes (Basic auth registry key, 50,000,000-byte limit, `Win32*` properties): https://sabre.io/dav/clients/windows/
- [S5] Nextcloud user manual, Accessing files using WebDAV (`FileSizeLimitInBytes`, 4 GB max): https://docs.nextcloud.com/server/latest/user_manual/en/files/access_webdav.html
- [S6] sabre/dav, davfs2 notes (class 2 required, `nolocks`): https://sabre.io/dav/clients/davfs/ ; davfs2 project: https://savannah.nongnu.org/projects/davfs2
- [S7] rclone WebDAV backend (vendors: nextcloud, owncloud, other, …): https://rclone.org/webdav/
- [S8] Cyberduck WebDAV: https://cyberduck.io/webdav/
- [S9] sabre/dav, Locks: https://sabre.io/dav/locks/
- [S10] sabre/dav, Temporary files: https://sabre.io/dav/temporary-files/
- [S11] RFC 4331, Quota and Size Properties for DAV Collections: https://datatracker.ietf.org/doc/html/rfc4331
- [S12] sabre/dav, PATCH support: https://sabre.io/dav/http-patch/
- [S13] Nextcloud developer manual, WebDAV basic APIs (`/remote.php/dav/files/{user}`, `oc:`/`nc:` props): https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/basic.html
- [S14] Nextcloud developer manual, Chunked file upload (v2 limits, 24 h expiry): https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/chunking.html
- [S15] Nextcloud developer manual, Login Flow (v2): https://docs.nextcloud.com/server/latest/developer_manual/client_apis/LoginFlow/index.html
- [S16] Nextcloud desktop client source: https://github.com/nextcloud/desktop ; ownCloud client: https://github.com/owncloud/client
- [S17] Nextcloud developer manual, OCS API: https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/index.html
- [S18] Nextcloud developer manual, Remote wipe: https://docs.nextcloud.com/server/latest/developer_manual/client_apis/RemoteWipe/index.html
- [S19] sabre/dav, Virtual filesystems (`INode`/`IFile`/`ICollection`): https://sabre.io/dav/virtual-filesystems/
- [S20] sabre/dav, Per-user directories: https://sabre.io/dav/per-user-directories/
- [S21] rclone serve webdav (`--auth-proxy`, `--user-from-header`, `--htpasswd`): https://rclone.org/commands/rclone_serve_webdav/
- [S22] hacdias/webdav: https://github.com/hacdias/webdav
- [S23] Laravel 12 filesystem (streams, disks, S3): https://laravel.com/docs/12.x/filesystem
- [S24] RFC 6578, Collection Synchronization for WebDAV: https://datatracker.ietf.org/doc/html/rfc6578
- [S25] sabre/dav, Large files (streams, output buffering off): https://sabre.io/dav/large-files/
- [S26] nginx X-Accel: https://www.nginx.com/resources/wiki/start/topics/examples/x-accel/
- [S27] nginx core module (`client_max_body_size`, `client_body_timeout`): https://nginx.org/en/docs/http/ngx_http_core_module.html
- [S28] PHP core ini settings: https://www.php.net/manual/en/ini.core.php
- [S29] Nextcloud admin manual, Uploading big files: https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/big_file_upload_configuration.html

All URLs above returned HTTP 200 on 2026-11-13.

Codebase, read only: `app/Dav/Factories/DavServerFactory.php`,
`app/Dav/Controllers/DavController.php`, `app/Dav/Auth/LaravelAuthBackend.php`,
`app/Dav/Plugins/*.php`, `app/Dav/Services/{DavService,Principals}.php`,
`app/Dav/Tests/{DavAuthTest,DavIsolationTest}.php`, `public/dav.php`,
`routes/web.php`, `routes/api.php`, `bootstrap/app.php`,
`config/{filesystems,logging,database}.php`, `app/Shared/Providers/DavServiceProvider.php`,
`database/migrations/2025_12_08_161442_create_dav_tables.php`,
`app/Api/Users/Services/UserService.php`, `app/Api/Export/**`,
`app/Api/ApiResponse.php`, `docs/STANDARDS.md`, `docs/backlog/dav-unhandled-post.md`,
`tests/Pest.php`, and the `vendor/sabre/{dav,http}` sources cited inline.
