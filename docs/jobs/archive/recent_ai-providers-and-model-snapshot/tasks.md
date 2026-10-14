# Tasks: AI providers and model snapshot

id: recent
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Context checked before planning

- Branch: worktree HEAD is `feature/recent_ai-providers-and-model-snapshot`, which matches `brief.md`.
- `app/Plugins/` does not exist yet. This job runs before `user-plugins-storage`, so it does the test and PHPStan wiring (brief, "Where it lives").
- Wiring that has to learn about `app/Plugins`:
  - `phpunit.xml`: only the suites `Unit`, `Feature` and `Api` (`app/Api/*/Tests`).
  - `tests/Pest.php:7-8`: `uses(TestCase)` / `uses(RefreshDatabase)`.
  - Root `pest.php`: a list of `->in(...)` dirs. `TESTING.md` says Pest discovers tests through it, and `user-plugins-storage` does not mention it.
  - `phpstan.neon` `excludePaths`: `app/*/Tests/*` does not match `app/Plugins/Ai/Tests/*`.
  - `bootstrap/app.php:36-44` `withCommands`.
- Existing shapes to follow:
  - Enums: `App\Shared\Enums\AiUsageFeatureEnum`, a string-backed enum with UPPER_CASE cases.
  - Commands: e.g. `app/Api/Feeds/Commands/ImportFeeds.php`, which uses `Http::`.
  - Command tests: `app/Api/Users/Tests/CommandTest.php` and `app/Api/Notifications/Tests/CommandTest.php`.
  - Fixtures go in `tests/Fixtures/`.
- External checks done during planning (the developer re-confirms and records links in `implementation.md`):
  - models.dev `api.json` is about 5.6 MB. Model entries carry `id`, `name`, `structured_output` (sometimes missing) and `status` (seen as `"deprecated"`, and absent on current models).
  - OpenRouter documents `response_format: {type: "json_schema", ...}` on `https://openrouter.ai/api/v1/chat/completions`.
  - Mistral documents custom structured outputs through `response_format` with `json_schema`.
  - OpenAI is the reference implementation.
  - None of the three base URLs contradicted the brief.

## Task breakdown

TASK-1: Wire `app/Plugins` into the test and static-analysis setup so tests under `app/Plugins/*/Tests` are discovered and excluded from PHPStan, as `user-plugins-storage` describes.
  files:
  - `phpunit.xml`: a new testsuite, e.g. `Plugins` → `app/Plugins/*/Tests`.
  - `tests/Pest.php`: add `'../app/Plugins'` to both `uses(TestCase::class)->in(...)` and `uses(RefreshDatabase::class)->in(...)`.
  - `pest.php` (root): `->in('app/Plugins/*/Tests')`.
  - `phpstan.neon`: exclude `app/Plugins/*/Tests/*`.
  depends: none
  risk: medium. Tests that are silently not discovered look like green tests. Once TASK-6 exists, the developer must confirm with `vendor/bin/pest --list-tests` that the new tests show up, and that `composer test` still runs the same number of existing tests.

TASK-2: Add `App\Plugins\Ai\Enums\AiProviderEnum`, a string-backed enum with the cases `openai`, `openrouter` and `mistral`, plus a method that returns each provider's fixed base URL through a `match`.
  files: `app/Plugins/Ai/Enums/AiProviderEnum.php` (new)
  details:
  - Case values must equal the models.dev provider keys (`openai`, `openrouter`, `mistral`), because the command uses them as lookup keys.
  - Case names follow the repo's UPPER_CASE style.
  - Base URLs: `https://api.openai.com/v1`, `https://openrouter.ai/api/v1`, `https://api.mistral.ai/v1`.
  - Before committing, re-verify each URL and `response_format` `json_schema` support in the provider's current docs, and put the doc links in `implementation.md`.
  - No display-name method: the brief does not ask for one. `user-plugins-storage` will need `"name": "OpenAI"` later (see follow-ups).
  depends: none
  risk: low. A small enum. The only risk is a wrong URL, and verifying it is part of the task.

TASK-3: Add the snapshot reader, `App\Plugins\Ai\Services\AiModelSnapshot` (name is a suggestion). It reads only the committed `resources/data/ai-models.json` with `json_decode` and answers two questions: "which models does `<provider>` offer?" (list of `{id, name}`) and "is `<model>` available for `<provider>`?" (bool).
  files: `app/Plugins/Ai/Services/AiModelSnapshot.php` (new)
  details:
  - Both methods take an `AiProviderEnum`.
  - The file path must be overridable for tests, e.g. an optional constructor argument defaulting to `resource_path('data/ai-models.json')`, the same pattern as `AiService`'s optional client. Then the lookup test can use a fixed fixture snapshot instead of the committed file, which changes on every refresh.
  - Decode with `JSON_THROW_ON_ERROR`. A missing or corrupt committed file is a deploy bug, not a domain value.
  - A provider key that is missing from the file returns `[]` / `false`.
  - No caching. Nothing reads it yet, and the brief does not ask for it.
  - Return types per rule 4.6: `array<int, array{id: string, name: string}>` and `bool`.
  depends: TASK-2
  risk: low. Read-only code against a local file with no runtime callers yet.

TASK-4: Add the developer-only artisan command `ai:refresh-models` in `app/Plugins/Ai/Commands/`, and register that directory in `bootstrap/app.php` `withCommands` (rule 9.3).
  files:
  - `app/Plugins/Ai/Commands/RefreshAiModels.php` (new)
  - `bootstrap/app.php` (one new line in `withCommands`)
  what the command does:
  - Downloads `https://models.dev/api.json` with `Http::`.
  - For each `AiProviderEnum` case, reads `<provider>.models`.
  - Keeps an entry only if `structured_output === true` (a missing flag is excluded) and `status !== 'deprecated'` (a missing status is kept).
  - Writes `{ "<provider>": [ {"id": ..., "name": ...}, ... ] }` with providers sorted by key and models sorted by `id`. Use a byte-wise comparison (`strcmp`/`ksort` with `SORT_STRING`), not locale order.
  - Formats the output deterministically so diffs stay stable: `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and a trailing newline.
  - Writes to the same path the reader uses. That path must be overridable in tests (e.g. through the reader's path, or a command option) so that `composer test` never rewrites the committed file.
  - Not added to `routes/console.php`.
  unclear (conservative default proposed; the developer follows it unless the owner says otherwise): the brief does not say what happens when the download fails or a provider key is missing from models.dev. Proposed: the command exits non-zero and leaves the existing file untouched. A silent empty list would delete every model for that provider in the next committed diff.
  depends: TASK-2, TASK-3 (shared path)
  risk: medium. It parses an external payload of about 5.6 MB, and its output order and format are the whole point (stable diffs). It must never touch the committed file during tests.

TASK-5: Generate and commit the initial snapshot `resources/data/ai-models.json` by running `php artisan ai:refresh-models` once against the live models.dev.
  files: `resources/data/ai-models.json` (new; `resources/data/` does not exist yet)
  details:
  - The brief expects about 340 models today (OpenRouter 302, OpenAI 31, Mistral 8). Report the actual counts in `implementation.md`. A big deviation from those numbers is worth a sentence there, not a fix.
  - If the developer's environment has no outbound network access, stop and report `NEEDS-HUMAN-INPUT` rather than hand-writing the file.
  depends: TASK-4
  risk: medium. It needs a live outbound call from the dev environment. The diff is large and is the curated list that later validation uses, so it should come only from the command and never be edited by hand.

TASK-6: Pest tests in `app/Plugins/Ai/Tests/` for the command (`Http::fake()` plus a small models.dev fixture) and for the reader (a fixed fixture snapshot).
  files:
  - `app/Plugins/Ai/Tests/RefreshAiModelsCommandTest.php` (new)
  - `app/Plugins/Ai/Tests/AiModelSnapshotTest.php` (new)
  - `tests/Fixtures/models-dev.json` (new; small, hand-made)
  - `tests/Fixtures/ai-models.json` (new; fixed snapshot)
  command test cases:
  - Drops models with `structured_output: false` or no flag.
  - Drops `status: "deprecated"` and keeps another status or no status.
  - A non-enum provider in the fixture (e.g. `anthropic`) is not written.
  - Providers and models come out in sorted order even though the fixture lists them unsorted.
  - Only `id` and `name` are written per model.
  - The committed `resources/data/ai-models.json` is unchanged after the test.
  - If TASK-4's failure behaviour is adopted: a faked 500 response exits non-zero and leaves the target file unchanged.
  reader test cases:
  - The list for a provider.
  - `true` for a listed model, `false` for an unknown model, and `false` for a model that only exists under another provider.
  No live HTTP (TESTING.md).
  depends: TASK-1, TASK-3, TASK-4
  risk: low. Standard Pest and `Http::fake()` patterns already used in 8 files. The main thing to watch is that the tests actually run (TASK-1 check).

TASK-7: Run `composer test` and `composer analyse` in the worktree and fix only what this job introduced, so both exit 0.
  files: any file from TASK-1 to TASK-6 (no new baseline entries in `phpstan-baseline.neon` for new code)
  depends: TASK-1 to TASK-6
  risk: low. A verification step. Never run a bare `phpstan analyse` (CLAUDE.md).

## Follow-ups (not in this job's scope; for implementation.md / backlog)

- `user-plugins-storage` needs a display name per provider (`"name": "OpenAI"` in `GET plugins/ai/providers`). That entry, or a later one, can add a `label()` method to `AiProviderEnum`. It is not added here because the brief does not ask for it.
- Some OpenAI models on models.dev may be served only by the Responses API (e.g. `*-pro` or codex variants) and not by chat completions. The owner decided on no further narrowing. This matters for `ai-plugin`, not for this job.
- OpenRouter routes to sub-providers. Its docs recommend `provider.require_parameters: true` so that `response_format` is honoured. This is relevant to `ai-plugin`.
- `docs/STANDARDS.md` rule 9.3 names only `app/Api/<Module>/Commands` and `app/Bots/Commands`, and `docs/TESTING.md`'s layout table lists no `app/Plugins` suite. Both could mention `app/Plugins` once the owner wants the docs updated. This job does not edit them.
