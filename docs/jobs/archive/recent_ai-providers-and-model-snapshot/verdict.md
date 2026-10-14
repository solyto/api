# Verdict: AI providers and model snapshot

id: recent
reviewer: @reviewer
date: 2026-10-14

## Review

Reviewed on branch `feature/recent_ai-providers-and-model-snapshot` against `main` (`git diff main...HEAD`). Everything in the diff is either listed in `implementation.md` or is a job doc. Nothing changed outside the scope of the tasks.

Checks I ran in the worktree:
- `composer test`: 846 tests, exit 0. Every test prints the same "file_get_contents … .env" warning. That warning comes from the sandbox (no `/workspace/.env`) and is not caused by this change.
- `composer analyse`: `[OK] No errors`, exit 0. `phpstan-baseline.neon` is unchanged.
- `vendor/bin/pest --list-tests`: lists 7 tests under `App\Plugins`, so the new tests are discovered.
- Committed snapshot: providers are in the order `mistral, openai, openrouter`, and the models inside each provider are in byte-wise `id` order. Each entry has only `id` and `name`. Counts are 8 / 31 / 302, as stated in the brief. The file ends with a trailing newline.

TASK-1: PASS
notes: `phpunit.xml` has a new `Plugins` suite. The root `pest.php` has `->in('app/Plugins/*/Tests')`. `tests/Pest.php` binds `TestCase` and `RefreshDatabase` to `../app/Plugins`. `phpstan.neon` excludes `app/Plugins/*/Tests/*`. The test count went from 839 to 846, so no existing test was lost.

TASK-2: PASS
notes: `AiProviderEnum` is string-backed with UPPER_CASE case names. Its values equal the models.dev keys, and `baseUrl()` returns the three URLs from the brief through a `match`. The provider docs links and the `json_schema` checks are recorded in `implementation.md`.

TASK-3: PASS
notes: `AiModelSnapshot` takes an optional path in its constructor, defaulting to `resource_path('data/ai-models.json')`. It reads the file with `File::json(..., JSON_THROW_ON_ERROR)`. `models()` and `has()` return `[]` / `false` for a provider that is missing from the file. The return types are documented per rule 4.6. It does no caching.

TASK-4: PASS
notes: The `ai:refresh-models` filter keeps a model only if `structured_output === true` (a missing flag is dropped) and `status !== 'deprecated'` (a missing status is kept). It sorts providers with `ksort SORT_STRING` and models with `strcmp`, and encodes JSON deterministically with a trailing newline. It writes to the reader's `path()`, resolved from the container. On a connection error, a non-2xx response or a missing provider it exits non-zero without writing. The command directory is registered in `bootstrap/app.php` `withCommands` and is not in `routes/console.php`.

TASK-5: PASS
notes: `resources/data/ai-models.json` matches what the command produces: same order, same shape, counts as expected.

TASK-6: PASS
notes: The command test compares the exact output bytes. That one comparison covers the filter (`false` flag, missing flag, `deprecated`, `alpha`/`beta` statuses kept), the exclusion of the non-enum `anthropic` provider, the sort order from unsorted input, and the `id`/`name`-only shape. The other command tests check that the committed file is unchanged, and that a 500 response or a missing provider fails and leaves the file alone. The reader tests cover the list, `has()` returning true, false for an unknown model and false for a model under another provider, and a provider missing from the file. No test makes a live HTTP call.

TASK-7: PASS
notes: I re-ran `composer test` and `composer analyse` myself, and both exit 0 (see above).

## Security

Not run separately. From the correctness review: the base URLs are hard-coded in the enum, and the snapshot is plain JSON data that is never executed. Both match the SSRF reasoning in the brief.

## Overall

APPROVED

Nothing blocks merging.
