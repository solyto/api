# Backlog: Health response backwards compatibility

created: 2026-10-14

## What
`GET /api/v1/health` must keep `app` and `version` at the top level of the JSON body, next to the envelope that `ApiResponse::success` returns. Since `f3545db` (rule 1.5) the route returns `{success, message, data: {status, app, version}, timestamp}`. The frontend's self-hosted setup (`solyto/app` `src/routes/setup/+page.svelte:34-37`, the same at tag `1.0.15` and on `main` `8d70018`) reads `data.app === 'solyto'` and `data.version` from the top level. If either is missing, it shows the "invalid URL" error and the user cannot continue.

Fix: add `app` and `version` at the top level as well as in `data`. Add a test that pins both shapes (no health test exists today).

## Why
This change has not been deployed yet (it is in `1.0.15..main`). Once it is, no frontend build (web, F-Droid or desktop, old or current) can add a self-hosted server that runs it. Apps that are already set up are not affected, because the check only runs during setup. The "hosted" option is not affected either, because it does not call health. Installed F-Droid builds can't be changed, so the API has to keep the old shape for as long as those builds are supported.

## Decisions
- Keep the envelope (rule 1.5) and duplicate `app` and `version` at the top level. This is additive, so old and new clients both accept it.
- Rule 1.5 needs a note saying the health route is a pinned legacy contract (follow `STANDARDS_WORKFLOW.md`).

## Out of scope
Changing the frontend check. That belongs in `solyto/app`: it should accept either `data.app` or `data.data.app`, but it cannot fix builds that are already installed. The stale `'1.0.7'` fallback in `config/app.php:40` is also out of scope.

## Notes
Check: `curl -s localhost/api/v1/health | jq -e '.app == "solyto" and (.version|length>0) and .data.app == "solyto"'` exits 0. Source: the 1.0.15 → `main` compatibility check (2026-10-14) and `docs/reports/frontend-backwards-compatibility-2026-10-14.md`.
