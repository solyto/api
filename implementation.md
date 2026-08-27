# Implementation: verify testing is safe to deploy

id: (ad-hoc, no job folder)
status: done
date: 2026-08-27

## Summary

Audited the test infrastructure introduced by the `delay_create-tests` job
(618-test Pest suite) for deployment safety and fixed three defects that made
the suite **unrunnable on a fresh checkout / CI** — i.e. the safety net was
broken before it could catch anything:

1. The test bootstrap required `storage/framework/testing/` to already exist;
   that directory is not part of a fresh checkout (the `.gitkeep` files
   whitelisted in `.gitignore` were never committed), so `touch()` on the DAV
   sqlite file failed and every test aborted with "Unable to create file".
   Even the (later removed) CI workflow forgot this directory, so CI would
   have failed at the same point.
2. `config('app.key')` is read from a Docker secret at runtime
   (`DockerSecretHelper::get('APP_KEY')` in `config/app.php`), which does not
   exist in tests → null → `DatabaseTokenRepository` crashed and the
   forgot/reset-password endpoints returned 500 (3 tests failed).
3. `config('services.ai.api_key')` is likewise a Docker secret → null → the
   `OpenAI\Factory::withApiKey(null)` call in `AiService::__construct`
   threw a TypeError before the mocked client could be injected (4 tests
   failed).

`composer test` is now green on a bare checkout: **639 tests, 1566
assertions, 0 failures**.

Deploy safety verified:

- Tests are fully isolated from real infrastructure: `tests/TestCase.php`
  forces the default connection to in-memory SQLite and remaps the dedicated
  `pgsql` (DAV) connection to a per-process file-backed SQLite, redirects
  webpush/telescope/pulse to sqlite and cache stores to array. No test can
  reach MariaDB, PostgreSQL or Redis. The DAV migration's PostgreSQL branch
  is byte-for-byte unchanged (verified in the archived review).
- Test code cannot leak into production: the `Tests\` namespace and all Pest
  bindings live under `autoload-dev` (not installed with `--no-dev`), there
  are no `environment('testing')` branches in app code, no test-only
  routes/commands/providers, and no test-only services are bound outside
  `tests/TestCase.php`.
- `make deploy` (Ansible) does not run the test suite, so a green/red suite
  cannot block or break deploys.

## Changes

- `tests/TestCase.php`
  - Create `storage/framework/testing/` before touching the DAV sqlite file
    (self-sufficient test bootstrap instead of relying on working-tree state).
  - Set `config('app.key')` to a fixed test key so encryption and the
    password-reset broker work without the Docker secret.
  - Set `config('services.ai.api_key')` to a dummy value so the OpenAI
    factory can be constructed (the client is always a mock in AiService
    tests). Both follow the file's existing config-override pattern.
- `phpunit.xml` — migrated to the PHPUnit 12 schema; the deprecated
  `<coverage cacheDirectory>` attribute was moved to the root
  `<phpunit cacheDirectory=".phpunit.cache">` element (silences the schema
  deprecation warning; keeps the coverage cache where the archived CI
  workflow expected it).

## Verification

- `composer test` → 639 tests / 1566 assertions / 0 failures, repeatable.
- Failing-tests count trajectory during the audit: could not boot at all →
  7 failed → 3 failed → 0 failed.
- Confirmations from static review: no `environment('testing')` in
  `app/`, `config/`, `bootstrap/`, `routes/`; `bootstrap/providers.php`
  clean; no leftover `Test*` artisan commands; `Makefile` deploy target runs
  Ansible only.

## Known issues / follow-ups

- **CI test workflow is absent** — the owner removed `.github/workflows/tests.yml`
  (commit 703d82a). The old workflow was broken anyway (it did not create
  `storage/framework/testing/`, so the suite could not boot there). Now that
  the suite is self-contained and green, re-adding a CI test job (plain
  `composer install` + `composer test`; optionally the old docker + coverage
  gate once a coverage driver is available) would restore the safety net.
  Deliberately not re-added here — it was explicitly removed by the owner.
- **No coverage driver** (xdebug/pcov) installed, so coverage percentages
  still cannot be produced (pre-existing, documented in the archived job).
- **`.env.example` is missing** from the repo while `composer.json`'s
  `post-root-package-install` copies it to `.env` on create-project, and a
  fresh checkout has no `.env` at all (tests work without it now, but the
  missing file breaks that composer hook and leaves devs without a starting
  env template). Recommend committing one.
- **`.gitkeep` files for `storage/`** are whitelisted in `.gitignore` but
  were never committed, so fresh checkouts lack the runtime storage
  directories. The test suite no longer depends on this, but the app itself
  still needs the dirs at runtime (deploy presumably creates them; committing
  the `.gitkeep` files would make checkouts self-sufficient).
- **`.env.testing` is tracked in git** with benign test config
  (sqlite/:memory:). Keeping it tracked is what makes `composer test` work
  out of the box; if it is ever untracked, `phpunit.xml` must carry
  `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` instead.
- **Sandbox-only artifacts, not repo issues:** in this agent workspace
  `.env.testing` is a bind-mounted `/dev/null` (cannot be removed), which
  breaks `is_file()` detection of the testing env file and produces a
  harmless per-test "file_get_contents(.env)" warning. On a real checkout the
  file is a regular file and the warning disappears. The root `AGENTS.md`
  working-tree modification is the harness's read-only mount of
  `docs/AGENTS.md`; it is not part of this job's changes.