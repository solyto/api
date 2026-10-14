# Tasks: backwards compatibility with frontend

id: variety
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Scope as understood

This job is a **report only** (`type: report`). The deliverable is one new
Markdown file under `docs/reports/`. It sets out a strategy (API contract
rules + deployment/release process) so that the web frontend can always run
the latest API, while older frontend builds (F-Droid / mobile / desktop) that
users have not updated keep working without odd bugs or side effects.

**No application code, config, migrations, routes or deployment files are
changed in this job.** Any implementation the report recommends (for example a
client-version header, a min-version check, CORS changes or CI contract diffing)
goes in as **proposed follow-up jobs** inside the report. None of it is built here.

Precedent to build on: `docs/reports/deployment-readiness-1.0.15-to-main.md`
already did a one-off "can the API ship without the frontend" analysis
(contract diff + "is it dark?" table + data-backfill leak via `.journal`). This
report should generalise that into a standing process. It should not repeat
that report.

## Facts already established while planning (for the developer to verify and cite)

- Every route lives under one `v1` prefix in `routes/api.php`. There is no
  version negotiation and no client identification of any kind (grep for
  `version|deprecat|X-Client|User-Agent` across routes/bootstrap/app/Shared/config
  hits only the health route).
- `GET /api/v1/health` returns `config('app.version')`, which defaults to
  `env('API_VERSION', '1.0.7')` in `config/app.php:40` (stale fallback, already
  in `STANDARDS.md` 1.5 / `STANDARDS_BACKLOG.md:39`).
- `config/cors.php`: `allowed_headers` is only `Content-Type, Authorization,
  X-Requested-With, Accept` and `exposed_headers` is `[]`. A custom request
  header (e.g. `X-Client-Version`) or a custom response header (e.g.
  `Deprecation`, `X-Min-Client-Version`) would therefore be blocked or
  invisible to a browser/webview client until CORS is changed. Allowed origins
  are `*.solyto.de`, `*.solyto.app` plus env. Whether the mobile/desktop
  shells send one of these origins is **unknown** (see open questions).
- `config/sanctum.php`: `'expiration' => null`. Tokens never expire, so an old
  install can stay logged in and active indefinitely. There is no natural
  point at which an old client is forced to re-authenticate or update.
- Error envelope and exception→status map are central (`App\Api\ApiResponse`,
  `bootstrap/app.php`). That gives one obvious place for an "unsupported
  client" response (e.g. 426), if one is proposed.
- `Makefile` `deploy` runs Ansible from a `deployment/` directory that is **not
  in this workspace**. The current deploy flow can only be described from the
  brief + Makefile, not verified.
- The frontend repository is **not in this workspace**.

## Task breakdown

TASK-1: Inventory the API's current compatibility-relevant surface (versioning, health/version reporting, CORS headers/origins, token lifetime, envelope/exception map, OpenAPI spec generation, DAV routes as a separate client surface) and record it as the report's "current state" findings with file:line citations.
  files (read only): routes/api.php, routes/web.php, config/app.php, config/cors.php, config/sanctum.php, bootstrap/app.php, app/Api/ApiResponse.php, app/OpenApi/OpenApiSpec.php, config/l5-swagger.php, Makefile, docs/STANDARDS.md (1.4, 1.5, 12.2, 12.4)
  depends: none
  risk: low. Read-only fact gathering, and most facts are already listed above.

TASK-2: Classify the kinds of API change that can break an old frontend, using this repo's actual history as evidence (git log/diff across release tags on routes/api.php, app/Api/*/{Requests,Resources,Services}, database/migrations). Cover: removed/renamed endpoints or fields, re-typed fields, tightened validation / newly required fields, changed semantics or defaults, data backfills visible through existing endpoints (the `.journal` case), new enum values, changed error codes, and auth changes.
  files (read only): git history of routes/api.php, app/Api/**/Requests, app/Api/**/Resources, app/Api/**/Services, database/migrations; docs/reports/deployment-readiness-1.0.15-to-main.md
  depends: TASK-1
  risk: medium. How far back to go is a judgement call; limit it to the tags since the F-Droid release (or the last ~5 tags if that date is unknown) and state the cut-off in the report.

TASK-3: Evaluate the candidate strategies against this codebase, with pros/cons/effort for each: (a) additive-only "expand/contract" rules inside `v1` plus a deprecation window; (b) side-by-side URL versions (`v2` prefix) for unavoidable breaks; (c) client-version identification (header) + server-advertised minimum supported version / forced-update response; (d) capability/feature flags so new behaviour is opt-in per client; (e) running multiple API deployments pinned per client version (likely rejected, but say why: single MariaDB + `pgsql` DAV schema, migrations, queues). Note the CORS and token-expiry implications from TASK-1 for each option.
  files (read only): same as TASK-1, plus app/Api/*/Resources and Requests as examples
  depends: TASK-1, TASK-2
  risk: medium. The best option depends on frontend behaviour that cannot be checked here (whether it can send headers, show an update prompt, or handle unknown fields/enum values), so recommendations must be conditional where they rely on it.

TASK-4: Define the proposed deployment/release process: contract rules for every PR (what is allowed in `v1`), expand → migrate → contract sequencing for DB migrations and backfills, a minimum support window for old clients tied to F-Droid's publishing lag, deprecation → sunset steps, how the web frontend keeps deploying in lock-step, a pre-deploy compatibility check (e.g. OpenAPI spec diff against the last released tag in CI), and how to observe which client versions are still in use (only proposing logging/Pulse use, not building it).
  files (read only): routes/console.php, config/pulse.php, config/telescope.php (only to check what could observe client versions), Makefile
  depends: TASK-3
  risk: medium. The deployment repo and CI setup are not in the workspace, so the process must be written as a proposal with explicit "needs verification in solyto/deployment" notes, not as a description of existing infrastructure.

TASK-5: List concrete follow-up implementation jobs, small and ordered, each with affected files and its risk (e.g. CORS header allow/expose, client-version middleware, min-version config + 426 mapping in `bootstrap/app.php`, fixing the `API_VERSION` fallback, an OpenAPI diff step, `STANDARDS.md` additions for compatibility rules). Flag any item that would touch `pgsql`/DAV routes, as the project hard rules require.
  files (read only): none new. Derived from TASK-3/TASK-4
  depends: TASK-3, TASK-4
  risk: low. A list only. It must not be implemented in this job.

TASK-6: Write the report to `docs/reports/frontend-backwards-compatibility-2026-10-14.md` in the same structure as the existing reports (header with `date:`/`scope:`/`read:`; `## Summary`, `## Findings` (F1…), `## Recommendations`, `### Open questions for the maintainer`, and a limitations section stating that the frontend and deployment repos were not available and no tests or migrations were run).
  files: docs/reports/frontend-backwards-compatibility-2026-10-14.md (new). No other file changes.
  depends: TASK-1, TASK-2, TASK-3, TASK-4, TASK-5
  risk: low. Docs only. The main risk is overstating certainty about the frontend or the deploy pipeline, so every such claim must be marked unverified.

## Unclear / open questions (do not guess; put them in the report as open questions)

1. How do the mobile and desktop builds talk to the API: Capacitor/Tauri/Electron webview or native HTTP? Which `Origin` do they send? This decides whether the CORS changes are needed for those clients.
2. Can the frontend send its own build version, and does it already show any "please update" UI? Does it tolerate unknown response fields and unknown enum values?
3. How long do F-Droid builds lag behind a tag, and what support window (e.g. N releases or N months) is the maintainer willing to commit to?
4. Is there a desktop distribution channel besides F-Droid that also lags (the brief mentions "mobile or desktop")?
5. Self-hosting: the reverse case (new frontend against an older self-hosted API) is **not** in the brief. The report should mention it in one line and treat it as out of scope unless the maintainer says otherwise.
6. Does the CalDAV/CardDAV surface (`routes/web.php`, `pgsql`) count as in scope? Third-party DAV clients are standards-based and not "the frontend", so the report should treat DAV as out of scope and say so.
