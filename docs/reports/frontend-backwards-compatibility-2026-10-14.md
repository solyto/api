# Report: Backwards compatibility of the API with older frontend builds

date: 2026-10-14
scope: How can the API keep working for frontend builds that users have not updated (F-Droid / mobile / desktop), while the web frontend keeps deploying in lock-step with the API? Covers the API's current compatibility surface, the kinds of change that broke or would break old clients in this repo's history (tags 1.0.10 → `main`), candidate strategies, a proposed release/deployment process, and follow-up jobs. Written for the maintainer. This report changes no code, config, routes, migrations or deployment files. Everything it proposes is a follow-up job.
read: false

## Summary

Until now the API and the frontend were always deployed together, so every API change only had to work with **one** frontend version. Since the F-Droid release, an installed app can run any older frontend build against the newest API. Nothing in the API is ready for that today:

- There is one `v1` URL prefix and **no client identification at all**. The API cannot tell which frontend version is calling, and it has no way to tell an old client "please update" (F1, F2).
- The history shows **every class of breaking change** in the last five releases: removed endpoints and fields (`authors`, 1.0.14 → 1.0.15), a field re-typed from string to array with a destructive migration (recipe `ingredients`, 1.0.13 → 1.0.15), tightened validation, a re-typed `id`, a new enum value, data backfills that leak into old UIs (`.journal`, see `deployment-readiness-1.0.15-to-main.md`), and changed error messages (F5).
- Release tags are **not linear**. Tag `1.0.14` sits on the unmerged `authors` branch and is not an ancestor of `1.0.15`. If 1.0.14 was ever deployed, a 1.0.14 app is now calling endpoints that no longer exist (F4).
- Mobile/desktop tokens last 90 days and can be refreshed without limit, so an old install stays logged in and active indefinitely (F2).

**Recommendation (for decision, not decided).** Combine three things. None of them needs a second API deployment or a `v2` prefix:

1. **Contract rules inside `v1` (expand → migrate → contract).** Only additive changes are allowed in a normal release. Anything that removes, renames, re-types or tightens follows a deprecation window tied to the oldest supported app version. This prevents most breaks and costs only discipline plus a pre-deploy check (Option A).
2. **Client version header + minimum supported version + a "please update" response (426).** This is the safety net for the rare break that cannot be avoided. It only works for builds that already send the header and know how to show the 426. So the frontend has to ship it **before** it is ever needed, and the API must allow the header in CORS **before** the frontend sends it (Option C).
3. **Observe before you remove.** Log the client version (and the platform already present in token names) so a "contract" step only happens once the old versions have actually gone.

Side-by-side `v2` routes (Option B) are kept as a last resort for a whole-domain rewrite. Per-client feature flags (D) and multiple pinned API deployments (E) are not recommended (F7).

## Method

- Read: `routes/api.php`, `routes/web.php`, `config/{app,cors,sanctum,l5-swagger,pulse,telescope}.php`, `bootstrap/app.php`, `app/Api/ApiResponse.php`, `app/OpenApi/OpenApiSpec.php`, `app/Shared/Enums/AuthPlatformEnum.php`, `app/Api/Users/{Controllers/AuthController,Services/AuthService}.php`, `app/Shared/Providers/TelescopeServiceProvider.php`, `routes/console.php`, `Makefile`, `docs/STANDARDS.md` (1.4, 1.5, 2.6, 12.2, 12.4, 12.6), `docs/reports/deployment-readiness-1.0.15-to-main.md`.
- History: tags found with `git log --tags --simplify-by-decoration`. The F-Droid release date is not recorded in the repo, so the cut-off is **the last five tags plus `main`**: `1.0.10` (`2204f41`, 2026-06-24) → `1.0.11` (`60b8ca0`) → `1.0.12` (`9f04983`) → `1.0.13` (`f46bc07`) → `1.0.14` (`3c353e0`) → `1.0.15` (`6bdedcd`, 2026-09-16) → `main` (`44243c3`). Contract diffs used `git diff <a> <b> -- routes/api.php app/Api/*/{Requests,Resources} database/migrations`.
- The 1.0.15 → `main` range was already analysed in `deployment-readiness-1.0.15-to-main.md`. This report cites it and only adds what that report did not cover (the 2026-10-13 standards rewrites).

## Findings

### F1 — Versioning and version reporting

- **One version, no negotiation.** Every route is registered in `routes/api.php` under `Route::prefix('v1')`: the public group at `:60` and the `auth:sanctum` group at `:80` (STANDARDS 1.4, 12.2). Nothing reads a client version, `Accept` version, `User-Agent` or custom header. A grep for `version|deprecat|X-Client|User-Agent` across `routes/ bootstrap/ app/Shared/ config/` finds only the health route. The one `User-Agent` in `app/` is outgoing (`FeedReader.php:93`).
- **Health reports the API version.** `GET /api/v1/health` (`routes/api.php:61-67`) returns `config('app.version')` through `ApiResponse::success`. `config/app.php:40` is `env('API_VERSION', '1.0.7')`. The route already follows STANDARDS 1.5. The **stale `'1.0.7'` fallback** remains: if `API_VERSION` is not set in production, an old client that reads `/health` gets the wrong version. Whether production sets it was not verified (deployment repo).
- **OpenAPI version is not the API version.** `app/OpenApi/OpenApiSpec.php:6-8` has `@OA\Info(version="1.0.0")`, and it has not changed across releases. The spec is generated from annotations (l5-swagger, `generate_always` defaults to `false`, `config/l5-swagger.php:241`). `storage/api-docs` is not committed, so no released spec can be diffed today without regenerating it from the tag.
- **Spec ≠ contract yet.** STANDARDS 2.6 documents drift between FormRequests, `@OA\RequestBody` and resource schemas. `tests/Unit/ResourceSchemaDriftTest.php` and `tests/Unit/OpenApiSpecTest.php` guard parts of it, with allow-lists. An OpenAPI diff is therefore a useful signal, but not a complete one (F8, follow-up J6).

### F2 — Auth and token lifetime: old clients never age out

- `config/sanctum.php:50` sets `'expiration' => null`, but tokens get a **per-token** `expires_at`. `AuthService::createToken` (`app/Api/Users/Services/AuthService.php:48-58`) uses `AuthPlatformEnum::tokenExpiryDays()` (`app/Shared/Enums/AuthPlatformEnum.php:11-17`): **web 7 days, mobile/desktop 90 days**. (The analyst's note said tokens never expire. That is only true for tokens created without `expiresAt`.)
- `POST /v1/auth/refresh` (`routes/api.php:84`, `AuthService::refreshToken`) deletes the current token and issues a new one with the same lifetime. An app that is opened at least once every 90 days stays logged in **for as long as it is used**. Token expiry is not a point at which an old build is forced to update.
- **There is already a coarse client marker.** Login, register, passkey auth and forgot-password accept `platform` (`in:web,mobile,desktop`, e.g. `LoginRequest.php:17`). The token is named `auth-token-{platform}`, and `personal_access_tokens` has `last_used_at` (migration `2025_08_09_131945…:20`). So "how many mobile/desktop installs were active in the last N days" can be counted today with one query. **Which version** they run cannot be counted.

### F3 — CORS: a custom header breaks the web app if the order is wrong

`config/cors.php`:
- `allowed_headers` (`:27`) is `Content-Type, Authorization, X-Requested-With, Accept`. A browser or webview client that sends `X-Client-Version` triggers a preflight. The preflight is rejected, and **the whole request fails**, not just the header. If the frontend starts sending the header before the API allows it, the web app (and webview-based apps) stop working.
- `exposed_headers` (`:28`) is `[]`. Response headers such as `Deprecation`, `Sunset` or `X-Min-Client-Version` are invisible to browser JS until they are exposed. Information that the client must read is safer **in the JSON body** (envelope `meta`) than in headers.
- Allowed origins (`:19-26`): env list (`CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`) plus `https://*.solyto.de` and `https://*.solyto.app`. The patterns require a subdomain, so the bare `https://solyto.app` is allowed only if it is in the env list (not verified). **Unverified:** which `Origin` the mobile/desktop shells send. Common webview shells use `https://localhost`, `capacitor://localhost` or `tauri://localhost`. None of these is in the patterns. So either the shells use native HTTP (no CORS), or production env lists them. This decides whether F3 matters for the apps at all (open question 1).

### F4 — Release lineage is not linear

- `git merge-base --is-ancestor 3c353e0 6bdedcd` → false. Tag **`1.0.14` is a single commit (`3c353e0`, "Add structured Author entity…") on the `authors` branch** off `703d82a`. It was never merged. `1.0.15` was cut from `main` and does not contain it.
- Contract effect, if 1.0.14 was deployed together with a 1.0.14 frontend: the 1.0.15 API **removed** `apiResource('authors')`, `POST authors/{author}/photo`, `POST authors/{author}/resync`, the `author_id` request rule and the `author_id` response field on books. The 1.0.14 migrations (`2026_08_19_100000_create_authors_table`, `…100001_add_author_id_to_library_books`) are absent from `1.0.15`. Their tables and columns, and their `migrations` rows, would still exist in a database that ran them, because nothing drops them.
- Whether 1.0.14 reached production or F-Droid is **not verifiable here**. In any case, a version number that is not an ancestor of the next one makes "minimum supported version" comparisons meaningless. Process rule P1 below addresses this.

### F5 — Breaking-change classes, with evidence from this repo

| Class | Example in history | Range | What an old client sees |
|---|---|---|---|
| Removed endpoint | `authors` resource + `photo` + `resync` | 1.0.14 → 1.0.15 (F4) | 404 on every author call |
| Removed response field | `LibraryBookResource.author_id` | 1.0.14 → 1.0.15 | `undefined` where an id was expected |
| Removed request field | `author_id` rule on Store/UpdateLibraryBookRequest | 1.0.14 → 1.0.15 | Field silently ignored, link lost |
| **Re-typed field (request and response) + destructive migration** | Recipe `ingredients`: `nullable\|string` → `nullable\|array` of `{name, amount, unit}`. `2026_07_24_120000_restructure_library_recipes` converts the column and **drops the string column** (`ff7c9c2`) | 1.0.13 → 1.0.15 | An old app that saves a recipe with string ingredients gets **422**. An old app reading a recipe gets an array where it expects text (likely `[object Object]` or a crash, unverified) |
| Re-typed response field | `MusicReleaseImport.id` / `artist_id`: `integer` → `integer\|string`, `artist_id` now nullable (Spotify ids) | 1.0.13 → 1.0.15 | Numeric parsing or equality checks fail on string ids |
| New enum value | `MusicServiceEnum::SPOTIFY = 'spotify'`; `LibraryTypeEnum::AUTHOR` removed (only relative to 1.0.14) | 1.0.13 → 1.0.15 | An unknown `service` value in lists. Effect depends on how the UI maps it (unverified) |
| Tightened validation | `UpdateTodoWorkspaceRequest.title` gained `max:50`. All `exists:` rules replaced by owner-scoped checks (standards rewrites, `ca1627b`) | 1.0.13 → 1.0.15; 1.0.15 → `main` | 422 where an old app used to succeed. The `exists:` change only affects requests with foreign ids, which a correct client never sends |
| Renamed request field (fix) | `UpdateTagRequest`: `title` → `name` (the model has only `name`) | 1.0.13 → 1.0.15 | Renaming a tag starts to work for clients that send `name`. Clients that send `title` keep being ignored, as before |
| Changed semantics / data backfill | `.journal` category backfill, statistics `color` source | 1.0.15 → `main` (see `deployment-readiness-1.0.15-to-main.md` F3) | An unexplained folder, 403 on edit, blank colours |
| Field made conditional | `FeedSubscriptionResource.url` and `DevRequestCommentResource.user` became `whenLoaded` (`ca1627b`) | 1.0.15 → `main` | No change as long as every service path eager-loads (`FeedService.php:110,148,206` do). A future path that forgets to load would **silently drop the key** for all clients |
| Changed error message | `'Calendar already exists'` → `'Calendar already exists.'`. 409 mappings moved to `bootstrap/app.php:91-97`. Controller `catch (\Exception)` blocks removed (STANDARDS 7.2) | 1.0.15 → `main` | Status codes unchanged. Clients that compare **message text** break (unverified whether the frontend does) |
| Auth change | `platform` + per-platform token expiry (`8e163d9`, 2026-06-03, before the cut-off) | — | Shows that the frontend already sends a client attribute at login, so a version can be sent the same way |

No change in this range altered the envelope (`ApiResponse.php`, apart from formatting) or a status code for an existing case.

### F6 — Central hooks that a strategy can use

- **One envelope** (`App\Api\ApiResponse`, STANDARDS 12.4). `success()`/`error()` already take a `meta` array (`ApiResponse.php:16,40`). Deprecation notices or a `min_client_version` can travel in `meta` without changing any resource.
- **One exception map** (`bootstrap/app.php:43-100`). An `UnsupportedClientException → 426` (or `HttpException(426)`, which `:84-89` already renders) needs one branch. The middleware stack is configured at `bootstrap/app.php:26-33` (only `trustProxies` and CSRF exceptions today). A client-version middleware would be appended there.
- **Deploy.** `Makefile` `deploy` runs `ansible-playbook` from `deployment/`, which is **not in this workspace**. The repo has **no CI**: `.github/workflows/tests.yml` was removed in `703d82a` (2026-08-16). Only `.github/dependabot.yml` remains. A pre-deploy contract check therefore needs a home: a `make` target, the deploy playbook, or restored CI (open question 7).
- **Observability.** Pulse is enabled with `UserRequests` (`config/pulse.php:227-233`), which counts requests per user, not per client version. Telescope in non-local environments only keeps failed requests, exceptions, failed jobs and scheduled tasks (`TelescopeServiceProvider.php:23-30`). Neither records a client version. A custom Pulse recorder or a structured log line would be needed (follow-up J8).

### F7 — Strategy options

| | Option | Fits this codebase | Effort | CORS / token implications | Verdict |
|---|---|---|---|---|---|
| A | **Additive-only `v1` + expand/contract + deprecation window** | Yes. Every break in F5 had an additive alternative (see the worked example below) | Low code, ongoing discipline. Needs a check (P6) and STANDARDS rules | None | **Adopt as the default** |
| B | **Side-by-side `v2` routes for unavoidable breaks** | Possible: a second `prefix('v2')` group in `routes/api.php`. It would be the first exception to STANDARDS 1.4 ("one file, two `v1` groups") | High per use: two controllers/resources per endpoint, double tests and annotations, two `@OA\Server`s | None | **Last resort**, per domain (e.g. a full recipes rewrite), never a global v2 |
| C | **Client version header + minimum supported version → 426** | Yes: middleware + one exception branch + config value | Low API side. The frontend must send the header and handle 426 (unverified) | Must add the header to `allowed_headers` **before** the frontend sends it (F3). Must not reject requests **without** the header: every build currently installed sends none | **Adopt as the safety net** |
| D | **Capability / feature flags per client** | Partly. The API could advertise capabilities in `/health` or `meta`. Per-client opt-in behaviour would need branching in services | Medium–high. Every flag is a long-lived branch in services (against STANDARDS "no abstractions not already present") | Needs the header (C) anyway | **Not as a general mechanism.** A server-advertised capability list may become useful for the self-hosting reverse case (out of scope) |
| E | **Multiple API deployments pinned per client version** | No. Single MariaDB schema + shared `pgsql` DAV schema + shared Redis queues/caches. Migrations from the newest version would run under the old code. Scheduled jobs would run twice | Very high, and it does not fix data-level changes (`.journal` backfill) | Routing by version header at the proxy | **Reject** |

**Worked example, the recipe change under Option A.** Instead of converting `ingredients` in place:
1. *Expand* (release N): add `ingredients_list` (array) next to `ingredients` (string). Requests accept either. If only the string is sent, the server parses it (the parser already exists in the migration). The resource returns both, and the string is rendered from the list. The new frontend uses `ingredients_list`.
2. *Migrate*: backfill `ingredients_list` from the string column (non-destructive).
3. *Contract* (once no supported client still reads `ingredients`, F2/J8): stop accepting string input, drop the string column in a later migration.
Old apps keep working throughout. The cost is one extra field for one support window.

**The 426 trap (Option C).** The installed F-Droid builds do not send a version header. The API cannot tell them apart from each other, and it cannot show them an update message they understand. So:
- "No header" must be treated as *legacy, allowed* for at least one support window after the first build that sends the header.
- Only builds that already send the header and render the 426 can be told to update cleanly.
- Cutting off header-less clients later is possible, but they will only see a generic error. How the current frontend shows an unknown 426 is unverified.
This is why the frontend half of C should ship **as soon as possible**, even though nothing will use it yet.

### F8 — What is out of scope and why

- **CalDAV/CardDAV** (`routes/web.php` `dav/{path?}` and the `dav.solyto.de` domain, `pgsql`). Its clients are third-party and standards-based, not the solyto frontend, and its contract is the RFCs. Nothing proposed here touches DAV routes or the `pgsql` connection/schema.
- **Telegram webhook** (`routes/web.php`): server-to-server, versioned by Telegram.
- **The reverse case** (a new frontend against an older self-hosted API) is not in the brief. It is mentioned once here and not analysed (open question 5).

## Recommendations

### Proposed release and deployment process

These are proposals, and nothing here exists yet. Items marked *(verify in solyto/deployment)* depend on the deployment repo, which was not available.

- **P1 — Linear releases.** Tags only on `main` (or a release branch that is merged back), and every tag is an ancestor of the next one. Hotfixes (as proposed in `deployment-readiness-1.0.15-to-main.md`, option B) are cherry-picked onto a release branch and then merged back into `main`. Decide what 1.0.14 was (F4, open question 6).
- **P2 — Contract rules for every PR inside `v1`** (to become STANDARDS rules, J9):
  - Allowed any time: new endpoints, new optional request fields, new response fields, new nullable columns with defaults, looser validation.
  - Needs the expand → contract sequence and a deprecation entry: removing or renaming an endpoint or field, changing a field's type or nullability, adding a required field, tightening validation, adding an enum value that the old UI shows, changing what an existing endpoint returns for existing data (backfills, filters, defaults), changing an error status.
  - Never in a single release: a destructive migration (drop/rename/convert a column) that an endpoint still in use depends on.
  - Error **status codes** are contract. Error **messages** are not (STANDARDS 7.4 keeps them English sentences). The frontend should not match on message text. If it needs to distinguish errors, add a machine-readable code (open question 2).
- **P3 — Expand → migrate → contract for data.** Migrations in a normal release are additive (STANDARDS 12.6 already says "additive, one per change"). Backfills that change what existing endpoints return are treated as behaviour changes (P2). The contract migration ships no earlier than one support window after the expand release.
- **P4 — Support window.** Proposal: support the client versions released in the last **90 days, and at least the last 3 releases**. 90 days matches the mobile/desktop token lifetime (F2), and F-Droid build lag adds to it (open question 3). A version leaves the window only after J8's numbers show it is (almost) unused.
- **P5 — Deprecation → sunset.**
  1. Mark the field/endpoint deprecated in `@OA` (`deprecated=true`) and in a `docs/DEPRECATIONS.md` list with the release and planned removal date.
  2. Optionally add `meta.deprecations` to affected responses (body, not header, because of F3).
  3. Raise `min_client_version` only for builds that send the header (F7, "426 trap").
  4. Remove in the contract release.
- **P6 — Pre-deploy compatibility check** against the last released tag:
  - `php artisan route:list --json` diff (built in, no new package): removed or changed method/URI = breaking.
  - OpenAPI spec diff (generate the spec at both refs). A dedicated diff tool (e.g. `oasdiff`) would be a new dependency, so ask first. Because of F1 drift, use it as a warning, not as proof.
  - `git diff <tag> -- database/migrations` reviewed for drops, renames, type changes and backfills.
  - Where it runs: there is no CI (F6). Options: a `make compat-check` target run before `make deploy`, a step in the Ansible playbook *(verify in solyto/deployment)*, or restoring a GitHub workflow.
- **P7 — Web frontend stays in lock-step.** Deploy order for every release: **API first, then web frontend** (the API is always ≥ the web build). The F-Droid/desktop builds are tagged from the same frontend commit, and that tag's version is what the client header reports. *(Verify in solyto/deployment whether API and web are deployed in one playbook run and in which order.)*
- **P8 — Observe client versions.** Log `X-Client-Version` + platform per request (sampled), or record it in a small Pulse recorder (J8). Until then, the `auth-token-{platform}` names and `last_used_at` give an active-install count per platform (F2).

### Follow-up jobs (ordered; none implemented here)

| # | Job | Files | Risk | Notes |
|---|---|---|---|---|
| J1 | Agree on P1–P5 (support window, linear tags, what 1.0.14 was) | `docs/` only | low | Decisions, no code |
| J2 | Fix the `API_VERSION` fallback: no stale literal, set the version at release time | `config/app.php:40`, deploy env *(verify)* | low | STANDARDS_BACKLOG already lists the health route. This is the remaining default |
| J3 | CORS: allow `X-Client-Version` in `allowed_headers`. Expose nothing unless a header-based signal is chosen. **Ship before J5's frontend change** | `config/cors.php:27-28` | low, but order-critical (F3) | Check that the mobile/desktop origins are covered (open question 1) |
| J4 | Client-version middleware: parse `X-Client-Version`, store it on the request for logging, and compare it with `config('app.min_client_version')` (null = off). Missing header = allowed. Below the minimum = 426 through the envelope | `bootstrap/app.php` (middleware + one render branch), new middleware under `app/Shared/`, `config/app.php`, tests | medium | Response body: `message` + `meta.min_client_version`. Do not apply to `health` and `auth/*`, so an outdated app can still read the minimum version and log in |
| J5 | (Frontend repo) Send `X-Client-Version` on every request, handle 426 with an update screen, tolerate unknown fields and enum values | frontend | medium | After J3 is deployed |
| J6 | Pre-deploy compat check (P6): route-list diff + migration review, and optionally an OpenAPI diff | `Makefile` or deployment repo, maybe a script under `tests/` | low–medium | A new tool (e.g. `oasdiff`) needs approval |
| J7 | Reduce OpenAPI drift so J6's spec diff can be trusted | annotations (STANDARDS 2.6 backlog) | low | Already tracked in `STANDARDS_BACKLOG.md` |
| J8 | Observe client versions: sampled log line or Pulse recorder (version, platform) | `app/Shared/`, `config/pulse.php` | low | Read-only, no personal data beyond what Pulse already stores |
| J9 | Write P2/P3/P5 into `docs/STANDARDS.md` (new "API compatibility" section) + `docs/DEPRECATIONS.md` | `docs/` | low | Follow `STANDARDS_WORKFLOW.md` |
| J10 | Retro-fix the current `main` leaks before the next deploy (hide `is_system`/`is_hidden` categories from old list calls, stats colour fallback) | Notes, TimeTracking services | medium | Already recommended in `deployment-readiness-1.0.15-to-main.md` |

**DAV / `pgsql` flag:** none of J1–J10 touches `routes/web.php`, the DAV backends or the `pgsql` connection/schema. If J4's middleware were registered globally rather than on the `api` group, it would also run on `dav/*`. It must be scoped to `api/*`.

### Open questions for the maintainer

1. How do the mobile and desktop builds call the API: a webview (Capacitor, Tauri, Electron) or native HTTP? Which `Origin` do they send? This decides whether J3 matters for them.
2. Can the frontend send its build version, and does it already have any "please update" UI? Does it tolerate unknown response fields and enum values? Does it match on error **messages** anywhere?
3. How long do F-Droid builds lag behind a tag? Is "90 days and at least 3 releases" (P4) acceptable?
4. Is there a desktop channel besides F-Droid that also lags (the brief says "mobile or desktop")?
5. Is the reverse case (a new frontend against an older self-hosted API) in scope? This report assumes not.
6. Was tag `1.0.14` (the unmerged `authors` branch) ever deployed or published to F-Droid? If yes, does production still have the `authors` table and `library_books.author_id`?
7. Where should a pre-deploy check run, now that the GitHub test workflow was removed (`703d82a`)?
8. Is the CalDAV/CardDAV surface correctly treated as out of scope?

## Limitations

- The **frontend repository is not in this workspace.** Every statement about how an old build reacts (unknown fields, 426, re-typed values, error messages, origins) is unverified and marked as such.
- The **deployment repository (`deployment/`, used by `make deploy`) is not in this workspace.** The current deploy order, environment (`API_VERSION`, `CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`) and whether 1.0.14 was deployed were not verified.
- The F-Droid release date is unknown, so the history cut-off (last five tags + `main`) is an assumption.
- No tests were run, no migrations were run, and no OpenAPI spec was generated. All contract diffs come from `git diff` on source files.
