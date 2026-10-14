# Verdict: backwards compatibility with frontend

id: variety
reviewer: @reviewer
date: 2026-10-14

## Review

Branch verified: `report/variety_backwards-compatibility-with-frontend`. Base: `main` (from `.manigot/manigot.json`).
`git diff main...HEAD` changes only the job files and the new report
`docs/reports/frontend-backwards-compatibility-2026-10-14.md`. No code, config, routes, migrations or deploy
files changed, as the report-only scope requires. `.env.testing` shows as modified in the worktree but is not in any
commit on this branch. implementation.md already discloses this.

TASK-1: PASS
notes: The current-state facts were checked against the source and match: `config/app.php:40` (`'1.0.7'` fallback),
`config/cors.php` allowed/exposed headers and origin patterns, `config/sanctum.php:50`, `routes/api.php` v1 groups and
health route, `OpenApiSpec.php` `version="1.0.0"`, `l5-swagger.php:241`, `bootstrap/app.php` middleware block and
HttpException branch, Telescope filter, Pulse `UserRequests`. The correction to the analyst's note about token expiry is
right: `AuthPlatformEnum::tokenExpiryDays()` gives 7 days for web and 90 for mobile/desktop, and
`AuthService::createToken` passes `expiresAt`.

TASK-2: PASS
notes: The cut-off (last five tags + main) is stated, as the task required. Checked: 1.0.14 (`3c353e0`) is not an
ancestor of 1.0.15, and the 1.0.14→1.0.15 diff removes `AuthorController`. The recipe `ingredients` string→array change
in the Requests is confirmed, and `restructure_library_recipes` was added in 1.0.13→1.0.15. `MusicServiceEnum::SPOTIFY`
exists. CI removal in `703d82a` is confirmed. Minor wording issue, not a blocker: the "Tightened validation" row says
`UpdateTodoWorkspaceRequest.title` "gained `max:50`", but in 1.0.13 that request had no `title` rule at all, so this was
a new optional field (an additive change) rather than a tightening. The `exists:` example in the same row still
illustrates the class.

TASK-3: PASS
notes: All five options A–E are evaluated with fit, effort, CORS/token implications and a verdict. Where the outcome
depends on frontend behaviour, the recommendation is conditional and marked unverified. The "426 trap" point (old
installs send no header, so a missing header must be allowed) is correct and important.

TASK-4: PASS
notes: P1–P8 cover every element the task asked for: contract rules, expand→migrate→contract, support window, deprecation
→ sunset, web lock-step, the pre-deploy check, and observation. Dependencies on the deployment repo are marked
"verify in solyto/deployment". Missing CI is noted as an open question, not assumed.

TASK-5: PASS
notes: J1–J10 are ordered, with files and risk for each. The DAV/`pgsql` flag is present, including the note that J4's
middleware must be scoped to `api/*`. Nothing was implemented.

TASK-6: PASS
notes: The report is at the specified path and follows the structure of the existing reports (header with
date/scope/read, Summary, Findings F1–F8, Recommendations, Open questions, Limitations). Every open question from
tasks.md is carried over. Limitations state that the frontend and deployment repos were unavailable and that no tests,
migrations or spec generation were run.

## Security

Not run. This is a docs-only change. No secrets appear in the diff.

## Overall

APPROVED

No blockers.
