# Tasks: STANDARDS

id: dawn
analyst: @analyst
date: 2026-10-13

<!-- Produced by @analyst from brief.md. -->

## Context for the developer

The model to replicate is the frontend repo's `docs/` set
(https://github.com/solyto/app/tree/main/docs): `STANDARDS.md` (numbered
rules per section, each from real code, with bad/good examples by file:line
and a check where one exists; then `## Decisions` and `## Open questions`),
`STANDARDS_BACKLOG.md` (checklist grouped by size — Mechanical / Extract to
helpers / Rewrites / Larger / Audits — every item names a file and the rule it
serves, with a grep "check line" where possible), `STANDARDS_WORKFLOW.md`
(how the document is built and resumed) and a trimmed `TESTING.md` (only how
to write tests). The frontend folded its older AI-written policy docs into one
section of `STANDARDS.md` and deleted them; this repo's equivalent is the
nine `docs/CONVENTIONS-*.md` + `docs/API-CONVENTIONS.md` written by job
`online_php-laravel-api-best-pratices` (2026-10-10). Their reviewer verified
the quoted snippets resolve, but the brief says to treat them with a grain of
salt — verify, don't trust.

Human reference code: the Todos module (`app/Api/Todos/`) is the owner's
reference per the earlier brief ("Most of the API I've developed myself").
Known AI-built changes: everything under `docs/jobs/archive/*` (Spotify
integration, time-tracking improvements, notebook folders, future releases,
long-term cache store, create-tests, ...). Use `git log` for the rest
(TASK-2).

Baseline numbers already measured (re-run before citing; counts are
occurrences across files under `app/` unless stated):

- `path="/api/` in `@OA` annotations: 186 hits / 33 controller files;
  `path="/v1/`: 82 / 15 files. Real prefix is `/api/v1`, so at least one
  group is wrong (`TodoController` uses `/v1/...`, `LibraryBookController`
  uses `/api/...`).
- Inline envelope schema (`@OA\Property(property="success"...` instead of
  `allOf={@OA\Schema(ref="#/components/schemas/SuccessResponse")}`): 53 / 20
  files; `allOf SuccessResponse`: 80 / 15 files.
- `ref="#/components/schemas/ValidationErrorResponse"`: 21 / 21 files vs
  `ErrorResponse` for 422 in Todos.
- `$request->validate(` inside `app/Api/*/Controllers`: 22 / 16 files
  (FormRequests exist for the rest).
- `catch (\Exception|\Throwable)`: 41 / 13 files; `CalendarController` 10,
  `ContactController` 6 — despite the central exception → JSON map in
  `bootstrap/app.php`.
- `Log::` calls: 27 / 8 files. `auth()`/`Auth::` in app code: 2 (good).
  `response()->json(` outside `ApiResponse`: 0 in `app/`, 1 in
  `routes/api.php:61` (health). `env(` in `app/`: 0; `routes/api.php:65`
  reads `env('API_VERSION')` at runtime (breaks under `config:cache`).
  `dd(`/`dump(`: 0. `TODO/FIXME`: 0. `declare(strict_types=1)`: 0.
- `// ` line comments in `app/Api`: 69 / 27 files (mostly tests;
  `CalendarApiTest` 15, `RepairCorruptedVCards` 7).
- Tests: 720 `it(`/`test(` across 59 files; `tests/Feature/` contains only
  `Pest.php`.
- Tooling: Pest (`pestphp/pest: "*"`, unpinned), Pint installed with **no**
  `pint.json` and clear evidence it is not run (`fn() =>`, `!$x`, `{` on the
  signature line at `app/Api/ApiResponse.php:69`, double blank line at
  `:104-105`, column-aligned `=>` in `LibraryBookService.php:20-23`). No
  PHPStan/Larastan, no Rector, no CI workflow (`.github/` has only
  `dependabot.yml`, composer only), `phpunit.xml` `<coverage/>` empty,
  `.editorconfig` present. `composer.json` still carries the skeleton
  `"name": "laravel/laravel"` and `"license": "MIT"` (project is AGPL-3.0).
  `tests/Pest.php` keeps skeleton residue (`function something()`,
  `toBeOne`). `docs/CLAUDE.md` is an unfilled template.

Hard constraints for this job: documentation only — no application code
changes, no package installs (tooling additions are *recommendations* in the
backlog, not actions), never touch `app/Dav/` or the `pgsql` connection, never
print `.env`/secret values. Edit `docs/AGENTS.md`, never the read-only mount
`/workspace/.claude/CLAUDE.md`.

## Task breakdown

TASK-1: Write the tooling/testing inventory report: what exists (Pest 4 via
`composer test`, Pint without config, `.editorconfig`, Dependabot, `composer
audit`), what is missing (static analysis, CI, coverage, pinned test deps,
Pint enforcement), with evidence lines, and a short recommendation list that
explicitly says each addition needs owner approval (package rule).
     files: docs/jobs/dawn_standards/tooling-report.md (new; precedent:
            docs/jobs/archive/delay_create-tests/coverage-report.md);
            reads composer.json, phpunit.xml, .editorconfig, Makefile,
            package.json, .github/dependabot.yml, tests/Pest.php,
            tests/TestCase.php, app/Api/Pest.php
     depends: none
     risk: low — read-only inventory; only risk is overstating "not run" for
           Pint, so cite the unformatted lines.

TASK-2: Build the provenance map (human vs AI) used to pick "good sibling"
examples: per module, date of first commit, commit-message style, burst size
(many files in one commit), cross-reference with `docs/jobs/archive/*`
briefs; record a table "module → human / AI / mixed → reference file to copy
from". State plainly if git attribution cannot separate the two (AI commits
may carry the owner's name).
     files: docs/jobs/dawn_standards/provenance.md (new); reads git history
            (`git log --format='%h %ad %s' --date=short -- app/Api/<Module>`),
            docs/jobs/archive/*/brief.md
     depends: none
     risk: low — informational; wrong guesses only affect which example a
           rule cites, and TASK-8 must quote code either way.

TASK-3: Controller + routing + OpenAPI audit. Measure and name the patterns:
`@OA` path-prefix drift, inline envelope schema vs `allOf SuccessResponse`,
`ValidationErrorResponse` vs `ErrorResponse`, generic `operationId`s
(`search`, `import` in `LibraryBookController`), inline `$request->validate`
vs FormRequest (decide the rule: the Todos subtask case is the owner's own
inline use), try/catch in controllers vs `bootstrap/app.php` map, duplicated
branches (`LibraryBookController::recommend` :131-156), `env()` + raw
`response()->json` in the health route, route grouping style in
`routes/api.php`, and what `OpenApiSpecTest` actually pins. Output: findings
with file:line and counts, ready to become rules + backlog items.
     files: docs/jobs/dawn_standards/findings-controllers.md (new, working
            notes; may be merged into TASK-8/9 instead of kept); reads
            app/Api/*/Controllers/*.php, routes/api.php, bootstrap/app.php,
            app/OpenApi/OpenApiSpec.php, tests/Unit/OpenApiSpecTest.php
     depends: TASK-2 (to label examples as human/AI)
     risk: low — read-only.

TASK-4: Services audit. Compare `TodoService` (human) against the other 59
module services and `app/Shared/Services`: repeated cache-forget pairs per
method, `call_user_func([$recommender, $type->value])`
(`LibraryBookService:118`), service-locator `app()->makeWith` inside a
service (`:143`), pass-through methods (`findGenre` `:170`), the 15 near-identical
`Export/Services/*ExportService.php`, `Log::` in services, swallowed
`catch (\Exception)`, methods doing more than one thing, `whereRaw` use,
return-type honesty (`mixed`, `?array`). Credit well-built pieces explicitly
(the workflow doc requires it).
     files: docs/jobs/dawn_standards/findings-services.md (new, working
            notes); reads app/Api/*/Services/**/*.php,
            app/Shared/Services/**/*.php
     depends: TASK-2
     risk: low — read-only; keep to observations, no refactors.

TASK-5: Requests / Resources / Models audit. FormRequests: `authorize()`
presence, pipe-string vs array rules, bare `exists:` on foreign ids
(`StoreTodoRequest:20-22` — check whether `TodoService::create` lets a user
attach another user's `category_id`/`tags`; if confirmed, this is a tenant-
isolation finding for the verdict's Security section and a top backlog
item, and the same check must be repeated for every `exists:` rule in
`app/Api/*/Requests`). Resources: `whenLoaded` use, `@OA\Schema` vs
`$fillable`/rules drift (the earlier job already noted `link` missing from
`TodoController` request bodies). Models: `$fillable` vs `$guarded`, casts,
`scopeForUser` naming, `HasUuids` consistency, relations typed.
     files: docs/jobs/dawn_standards/findings-data.md (new, working notes);
            reads app/Api/*/Requests/**/*.php, app/Api/*/Resources/*.php,
            app/Api/*/Models/*.php, app/Api/*/Factories/*.php
     depends: TASK-2
     risk: medium — the `exists:` question is security-relevant; verify by
           reading the service path end to end, do not assume.

TASK-6: Jobs / Commands / Notifications / Bots / DAV audit (lighter pass):
`$tries`/`$timeout` consistency, state re-read in `handle()`, schedules in
`routes/console.php`, commands registered in `bootstrap/app.php`, `catch`
blocks in `app/Dav/Helpers` (ICalendarHelper 5, VCardHelper 4) and
`Bots/Traits/IsTelegramBot`, translation-key messages in `app/Bots`. DAV is
describe-only: note findings, propose nothing that changes `app/Dav` or
`pgsql`.
     files: docs/jobs/dawn_standards/findings-background.md (new, working
            notes); reads app/Api/*/Jobs/*.php, app/Api/*/Commands/*.php,
            app/Api/*/Notifications/*.php, app/Shared/Notifications/**,
            app/Bots/**, app/Dav/**, routes/console.php
     depends: TASK-2
     risk: low — read-only; flag anything touching DAV rather than proposing
           changes.

TASK-7: Tests audit. Decide, like the frontend's `TESTING.md`, what gets a
test here: API contracts, tenant isolation, validation, service logic — vs
factory/model tests that test Laravel itself (`LibraryTest.php` has 70
`it(`; `UserTest` 35; `TodoTest` 25 — classify). Check helper use
(`makeUser`, `expectApiSuccess`) vs re-rolled auth, comment narration in
tests (`CalendarApiTest` 15 `//`), the empty `tests/Feature/`, skeleton
residue in `tests/Pest.php`, custom expectations that alias built-ins
(`greaterThan`, `lessThan`, `toBeBoolean`), and whether every module has a
tenant-isolation test.
     files: docs/jobs/dawn_standards/findings-tests.md (new, working notes);
            reads app/Api/*/Tests/*.php, app/Bots/Tests, app/Dav/Tests,
            tests/**
     depends: none
     risk: low — read-only.

TASK-8: Write `docs/STANDARDS.md` — "solyto api coding standards (draft)":
intro with the recurring themes, numbered sections (suggested: Controllers &
routing, OpenAPI annotations, Requests & validation, Services, Models &
queries, Resources, Errors & responses, Caching, Jobs/commands, Comments &
docblocks, Formatting, Architecture conventions [the folded CONVENTIONS
content, see TASK-12]), each rule as one-line statement + why + bad/good
example from this repo with file:line + check (grep) where one exists; then
`## Decisions` (owner choices that cannot be inferred — e.g. inline
`validate()` allowed for single-field child endpoints; `fn()` spacing; no
`strict_types`; Pint preset) and `## Open questions` (each with the data
point that raised it, including places where the owner's own Todos code
breaks a proposed rule). No component/class inventories. Where a rule rests
on framework behaviour (e.g. `env()` under `config:cache`, Pint `laravel`
preset defaults), confirm against the official Laravel 12 / Pint docs and
say so in one line.
     files: docs/STANDARDS.md (new)
     depends: TASK-2, TASK-3, TASK-4, TASK-5, TASK-6, TASK-7
     risk: medium — largest deliverable; the failure mode is generic
           Laravel advice not grounded in this repo. Every example must be a
           real file:line that exists on this branch.

TASK-9: Write `docs/STANDARDS_BACKLOG.md`: checklist grouped by size
(Mechanical / Extract to helpers / Rewrites / Larger / Audits / Tooling),
each item `- [ ] \`path:line\`: action (rule X.Y)` with a reproducible check
line (grep + expected count) where possible. Must include at minimum: the
`@OA` path-prefix unification, inline envelope → `allOf`, 422 schema ref
unification, `routes/api.php` health route (`env()` + raw json),
`composer.json` metadata (name/license), pinning `pestphp/*`, `tests/Pest.php`
residue, `docs/CLAUDE.md` template, and — as owner-approval items —
`pint.json` + a Pint run over the repo, PHPStan/Larastan, a CI workflow, and
Dependabot for npm. If TASK-5 confirms the `exists:` tenant issue, it goes
first and is marked as such.
     files: docs/STANDARDS_BACKLOG.md (new)
     depends: TASK-3, TASK-4, TASK-5, TASK-6, TASK-7, TASK-8 (rule numbers)
     risk: low — a list; sized items must stay one-commit-sized or be marked
           "larger".

TASK-10: Write `docs/TESTING.md` covering only how to write tests here
(runner + commands, the three PHPUnit suites, `TestCase::createApplication`
environment: in-memory SQLite, file-backed SQLite standing in for `pgsql`,
array cache stores, image-service double, fixed app key; `RefreshDatabase`
wiring; helpers; what gets a test and what deliberately does not; caveats).
Replace `docs/CONVENTIONS-TESTING-STYLE.md`'s content with verified facts —
e.g. do not describe `tests/Feature` as a home for existing suites.
     files: docs/TESTING.md (new)
     depends: TASK-1, TASK-7
     risk: low — short doc; verify every claim against tests/TestCase.php and
           phpunit.xml.

TASK-11: Write `docs/STANDARDS_WORKFLOW.md` adapted from the frontend's: goal,
roles (owner supplies examples; agent measures with grep over `app/`,
`git log`, and `composer test`), what the owner said about how to work (take
from the two briefs: prefer the human path, old policy docs are suspect),
shape of `STANDARDS.md`, where backlog items go, how to resume, and a "State
at the end of the first session (2026-10-13)" section listing covered areas
and still-to-do items.
     files: docs/STANDARDS_WORKFLOW.md (new)
     depends: TASK-8, TASK-9
     risk: low — process doc.

TASK-12: Fold the old policy set into `STANDARDS.md`: go through
`docs/API-CONVENTIONS.md` and the eight `docs/CONVENTIONS-*.md`, check each
rule against the code (counts, not trust), merge the still-valid ones into
the "Architecture conventions" section of `STANDARDS.md` (as the frontend did
in its §15), and record under `## Decisions` which old rules were dropped or
corrected and why.
     files: docs/STANDARDS.md (edit); reads docs/API-CONVENTIONS.md,
            docs/CONVENTIONS-CONTROLLERS.md, docs/CONVENTIONS-SERVICES.md,
            docs/CONVENTIONS-REQUESTS-RESPONSES.md,
            docs/CONVENTIONS-MODELS-DATABASE.md,
            docs/CONVENTIONS-RESOURCES-OPENAPI.md,
            docs/CONVENTIONS-AUTH-SECURITY.md, docs/CONVENTIONS-JOBS-QUEUES.md,
            docs/CONVENTIONS-TESTING-STYLE.md
     depends: TASK-8
     risk: medium — easy to carry over an unverified claim; each merged rule
           needs a fresh file:line on this branch.

TASK-13: Delete the superseded policy files and repoint references:
remove `docs/API-CONVENTIONS.md` and the eight `docs/CONVENTIONS-*.md`;
update the `## Conventions` paragraph in `docs/AGENTS.md` and the sentence at
`README.md:46` to point at `docs/STANDARDS.md` (+ `TESTING.md`); grep `docs/`
and the repo for remaining `CONVENTIONS` links. This mirrors the frontend
precedent (merged then deleted on 2026-10-12). Do this as its own commit so
it can be reverted independently if the owner prefers to keep the old set.
     files: docs/API-CONVENTIONS.md (delete), docs/CONVENTIONS-*.md (delete,
            8 files), docs/AGENTS.md, README.md
     depends: TASK-12
     risk: medium — deletion is the one judgment call in this job; the brief
           says "replicate" and the precedent deleted, but it did not say
           "delete" explicitly. Separate commit keeps it reversible.

## Suggested order

TASK-1, TASK-2, TASK-7 (independent) → TASK-3, TASK-4, TASK-5, TASK-6 →
TASK-8 → TASK-12 → TASK-9 → TASK-10 → TASK-11 → TASK-13.

The working-notes files from TASK-3..7 are job artefacts; if the developer
prefers to write findings straight into `STANDARDS.md`/the backlog, the notes
files may be skipped — but the counts and file:line evidence must land
somewhere reviewable.

## Assumptions and open points

- Tooling report location: job directory (`docs/jobs/dawn_standards/
  tooling-report.md`), following the `coverage-report.md` precedent, with the
  durable "how we test" part in `docs/TESTING.md` and tooling decisions in
  `STANDARDS.md ## Decisions`. If the owner wants a permanent
  `docs/TOOLING.md` instead, that is a rename.
- Deleting the CONVENTIONS set (TASK-13) follows the frontend precedent; it is
  isolated so it can be dropped.
- No tooling is added or run beyond `composer test` / `./vendor/bin/pint
  --test` (dry run, no writes) to gather evidence; adding `pint.json`,
  PHPStan, CI etc. are backlog items needing approval.
- "Human vs AI" may not be separable from git metadata alone; TASK-2 must say
  so rather than invent attribution.
- The brief's "Why" / "Out of scope" / "Notes" sections are empty; nothing in
  them constrains the above.
