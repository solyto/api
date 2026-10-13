# Tooling & testing inventory

id: dawn
date: 2026-10-13
author: developer (claude-fable-5-1)

Scope: what exists in this repository for testing, formatting, linting,
static analysis, CI and dependency hygiene; what is missing; and what is
recommended. Every "exists" / "missing" claim below names the file it was
read from. Nothing was installed or run for this report beyond `git` and
`grep`; see "Limits" at the end.

## What exists

| Area | Evidence | Status |
| --- | --- | --- |
| Test runner | `composer.json` `require-dev`: `pestphp/pest: "*"`, `pestphp/pest-plugin-laravel: "*"`; `composer.lock` resolves `pestphp/pest v4.4.1` | Pest 4, **unpinned** (`*`) |
| Test command | `composer.json` `scripts.test` = `php artisan config:clear` + `php artisan test`; `README.md:43` | `composer test` / `php artisan test` |
| Suites | `phpunit.xml` `<testsuites>`: `Unit` → `tests/Unit`, `Feature` → `tests/Feature`, `Api` → `app/Api/*/Tests`; `pest.php` (root) adds `app/Bots/Tests`, `app/Dav/Tests` | 3 PHPUnit suites + 2 Pest-only dirs |
| Test bootstrap | `tests/TestCase.php::createApplication()` (in-memory SQLite as default, file-backed SQLite standing in for `pgsql`, array stores for `user_data` / `longterm` / `conversation_state`, fixed `app.key`, `services.ai.api_key` stub, `ImageTransformationService` no-op double, `connectionsToTransact = ['sqlite','pgsql']`) | works on a bare checkout since `62dc51c` (2026-08-27) |
| Test env | `phpunit.xml` `<php>`: `APP_ENV=testing`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `SESSION_DRIVER=array`, `PULSE_ENABLED=false`, `TELESCOPE_ENABLED=false`, `PASSWORD_UNCOMPROMISED=false`, `EMAIL_CONFIRMATION=true`, `memory_limit=1G` | |
| Test helpers | `tests/Pest.php`: `makeUser()`, `sanctumToken()`, `authHeaders()`, `expectApiSuccess()`, `expectApiError()`; custom expectations `toBeBoolean`, `toBeEmail`, `greaterThan`, `lessThan`, `toBeOne` | see TESTING findings for which are dead |
| Test volume | 59 `*Test.php` files, 720 `it(`/`test(` blocks (`grep -rcE '^\s*(it|test)\(' app tests`) | last recorded green run: 639 tests / 1566 assertions (`implementation.md` at repo root, 2026-08-27) |
| Fixtures | `tests/Fixtures/feed.atom`, `feed.rss`, `feed_empty.rss`, `goodreads_book.html` | |
| Formatter | `composer.json` `require-dev`: `laravel/pint: ^1.13` (lock: `v1.27.1`) | installed, **no `pint.json`**, no composer script, no evidence of being run (below) |
| Editor settings | `.editorconfig`: 4-space indent, LF, final newline, trim trailing whitespace (`*.md` exempt), 2-space YAML | present |
| Git attributes | `.gitattributes`: `text=auto eol=lf`, diff drivers | present |
| Dependency updates | `.github/dependabot.yml`: `composer` ecosystem, weekly | composer only; **no `npm`** entry although `package.json` has 6 devDependencies |
| Vulnerability audit | `Makefile` `audit:` → `composer audit`; `README.md:42` | manual, not scheduled |
| Coverage config | `phpunit.xml`: `<source><include><directory>app</directory></include></source>` + empty `<coverage/>` | source map present, no driver, no threshold (`docs/jobs/archive/delay_create-tests/coverage-report.md` already recorded "no xdebug/pcov") |
| Observability | `laravel/pulse`, `laravel/telescope` (disabled in tests via `phpunit.xml`) | runtime, not dev tooling |
| OpenAPI | `darkaonline/l5-swagger ^10.1`; `tests/Unit/OpenApiSpecTest.php` parses annotations and compares to the route table | the test pins **80** known-stale operations (`OPENAPI_KNOWN_DRIFT`) instead of fixing them |

## Evidence that Pint is not run

Pint ships with the `laravel` preset by default (`vendor/laravel/pint/resources/presets/laravel.php`,
read from the upstream repository since `vendor/` is not present here). The
preset enables, among others, `not_operator_with_successor_space` (`! $x`),
`function_declaration` with the default closure spacing (`fn () =>`),
`binary_operator_spaces: single_space` (no column-aligned `=>`),
`braces_position.functions_opening_brace: next_line…`, `ordered_imports`
(alphabetical) and `no_extra_blank_lines`. The tree violates each of them:

- `!$x` without space: 137 lines vs `! $x`: 54 lines (`grep -rn '!\$' app --include=*.php | wc -l`).
- `fn()` without space: 48 lines vs `fn (`: 313 (`grep -rn 'fn(' app --include=*.php | wc -l`). Examples: `app/Api/Todos/Services/TodoService.php:27`, `app/Api/Finances/Services/WealthService.php:23`.
- Column-aligned `=>` (3+ spaces before the arrow): 152 lines in 30 files, e.g. `app/Api/Libraries/Services/LibraryBookService.php:20-23`, `app/Api/Todos/Services/TodoService.php:236-248`, `app/Bots/Messages/SolytoMessage.php:7-30`.
- `{` on the signature line: `app/Api/ApiResponse.php:69`, `app/Api/Feeds/Commands/ImportFeeds.php:102`.
- Double blank line: `app/Api/ApiResponse.php:104-105`.
- Unordered imports: `bootstrap/app.php:3-15` (`Illuminate\Http\Request` before `Illuminate\Foundation\Application`).

The mixed ratios (both spellings of `!`/`fn` are common) say the files were
never normalised as a whole; the AI test job of 2026-08-14 appears to have
formatted the files it touched (`fn () =>` dominates in `Resources/` and
`Tests/`), the owner's own files keep `fn()`/`!$`.

## What is missing

| Gap | Evidence |
| --- | --- |
| Static analysis | no `phpstan.neon`, no `larastan`, no `rector.php` in the tree; `composer.json` has no such dev dependency |
| CI | `.github/` contains only `dependabot.yml`. A workflow existed for two days: added in `4f7deeb` (2026-08-14, "ci: run tests in docker with coverage gate", `--min=60`) and removed in `703d82a` (2026-08-16, "remove test workflow") |
| Pint enforcement | no `pint.json`, no `composer lint`/`format` script, not referenced in `Makefile` |
| Pinned test deps | `pestphp/pest: "*"` and `pestphp/pest-plugin-laravel: "*"` (every other dependency is caret-pinned) |
| Coverage driver / threshold | `<coverage/>` is empty; no `--min` anywhere now that the workflow is gone |
| Dependabot for npm | only `composer` in `.github/dependabot.yml` |
| Project metadata | `composer.json`: `"name": "laravel/laravel"`, `"description": "The skeleton application…"`, `"license": "MIT"` — the project is AGPL-3.0 (`LICENSE`, `README.md:58`) |
| Skeleton residue | `tests/Pest.php:18-20` (`toBeOne`), `:66-69` (`function something()`); `tests/Unit/Pest.php` and `tests/Feature/Pest.php` re-bind `TestCase` for directories that `tests/Pest.php:6` already binds; `tests/Feature/` contains only `Pest.php` |
| Project context | `docs/CLAUDE.md` is the unfilled manigot template; `AGENTS.md` at repo root is an empty tracked file; `implementation.md` at repo root is an ad-hoc leftover from 2026-08-27 |
| Tracked artefacts | `tmp/export_0198c253-….zip` (77 KB, a user-data export, tracked since `072fea9`); `bootstrap/cache/packages.php` and `services.php` (generated, tracked since `b10b48c`) |

## Recommendations (each needs owner approval — package rule)

The project rule is "NEVER install packages without asking". None of the
following was installed or configured; they are backlog items with the
decision left to the owner (see `docs/STANDARDS_BACKLOG.md` → Tooling).

1. **Decide on Pint.** Either commit to it (`pint.json` with `"preset": "laravel"`
   plus the few deliberate deviations, a `composer lint` script, one
   repo-wide `./vendor/bin/pint` commit) or remove it from `require-dev`.
   Keeping it installed but unused is the worst of both. Cost: one big
   mechanical diff; benefit: the `!$`/`fn()`/aligned-`=>` discussions end.
   No install needed — the package is already in the lock file.
2. **Pin Pest.** `"pestphp/pest": "^4.4"`, `"pestphp/pest-plugin-laravel": "^4.0"`
   (already resolved in `composer.lock`). No install needed, lock file is
   unchanged.
3. **Static analysis (requires a new dev package).** `larastan/larastan` at
   level 5 would have flagged the `mixed` / `?array` return types in the
   Libraries services and the untyped relation methods in `User.php`. This
   is the one addition with real payoff for AI-written code; it needs
   `composer require --dev` and therefore approval.
4. **CI.** A minimal GitHub Actions workflow running `composer test` on PRs
   (the removed `4f7deeb` version built a Docker image and gated on 60 %
   coverage, which needed a coverage driver and was deleted two days later —
   start smaller: plain `php 8.4` + `composer install` + `php artisan test`).
   No package needed, but it is infrastructure and therefore the owner's call.
5. **Dependabot for npm.** Add a second `package-ecosystem: "npm"` block;
   no package needed.
6. **Coverage.** Only worth doing after CI exists; `pcov` as a dev
   dependency of the CI image, not of the project.

## Limits of this report

- `vendor/` is not present in the session, so `./vendor/bin/pint --test`
  and `composer test` could not be run; the Pint claims above are from
  grep against the preset's rules, not from Pint's own output.
- Counts are from 2026-10-13 on branch `feature/dawn_standards` and are
  reproducible with the greps given.
