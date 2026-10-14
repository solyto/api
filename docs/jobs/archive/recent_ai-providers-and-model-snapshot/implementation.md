# Implementation: AI providers and model snapshot

id: recent
developer: @developer
date: 2026-10-14

## Summary

Added the curated AI provider/model list under `app/Plugins/Ai/`. It has three parts:
- `AiProviderEnum` holds OpenAI, OpenRouter and Mistral, each with a fixed base URL.
- `ai:refresh-models` is a developer-only command. It builds the committed snapshot `resources/data/ai-models.json` from models.dev.
- `AiModelSnapshot` reads that file, and only that file.

This job is the first to use `app/Plugins`, so it also wires that directory into Pest, PHPUnit and PHPStan. Nothing in the app calls the new code yet.

Provider checks, done 2026-10-14:
- **OpenAI.** `https://api.openai.com/v1`. The docs (https://platform.openai.com/docs/guides/structured-outputs) show `response_format` `json_schema` on `https://api.openai.com/v1/chat/completions`. The base URL answers 401 without a key, so the endpoint exists.
- **OpenRouter.** `https://openrouter.ai/api/v1`. The docs (https://openrouter.ai/docs/features/structured-outputs) show `response_format: {type: "json_schema"}` on `https://openrouter.ai/api/v1/chat/completions`. `/models` answers 200.
- **Mistral.** `https://api.mistral.ai/v1`. The API reference (https://docs.mistral.ai/api) documents `response_format` with `json_schema` on `https://api.mistral.ai/v1/chat/completions`. The guide is at https://docs.mistral.ai/studio/conversations/structured-output; the old `capabilities/structured-output/...` link now redirects there. The base URL answers 401 without a key.

Snapshot counts from the live models.dev on 2026-10-14: OpenAI 31, OpenRouter 302, Mistral 8, 341 in total. That matches the brief.

## Changes

TASK-1: Wired `app/Plugins` into the test and analysis setup.
- `phpunit.xml` has a new `Plugins` suite for `app/Plugins/*/Tests`.
- The root `pest.php` has `->in('app/Plugins/*/Tests')`.
- `tests/Pest.php` binds `TestCase` and `RefreshDatabase` to `../app/Plugins`.
- `phpstan.neon` excludes `app/Plugins/*/Tests/*`.

Check: `pest --list-tests` shows all 7 new tests. The suite went from 839 to 846 tests, so the existing 839 still run.

TASK-2: Added `app/Plugins/Ai/Enums/AiProviderEnum.php`. It is string-backed and its values equal the models.dev keys. `baseUrl()` returns each URL through a `match`.

TASK-3: Added `app/Plugins/Ai/Services/AiModelSnapshot.php`.
- `models(AiProviderEnum)` returns `array<int, array{id, name}>`.
- `has(AiProviderEnum, string)` returns `bool`.
- The path defaults to `resource_path('data/ai-models.json')` and can be overridden through the constructor. `path()` exposes it so the command writes to the same file.
- The file is read with `File::json(..., JSON_THROW_ON_ERROR)`.
- A provider that is missing from the file gives `[]` / `false`.

TASK-4: Added `app/Plugins/Ai/Commands/RefreshAiModels.php` (`ai:refresh-models`) and registered its directory in `bootstrap/app.php` `withCommands`. It is not in `routes/console.php`.
- **Filter:** keeps a model only if `structured_output === true` and `status !== 'deprecated'`. A missing flag is dropped; a missing status is kept.
- **Output:** writes only `id` and `name`. Providers are sorted with `ksort` `SORT_STRING` and models with `strcmp` on the id. The JSON is pretty-printed with unescaped slashes and unicode and ends with a newline.
- **Target path:** it writes to `AiModelSnapshot::path()`, resolved from the container. Tests bind a temp path, so the committed file is never rewritten.
- **Failure handling:** I used the analyst's conservative default. A connection error, a non-2xx response or a missing enum provider makes the command exit non-zero without writing anything.

TASK-5: Created `resources/data/ai-models.json` by running `php artisan ai:refresh-models` against the live models.dev, and committed it without hand edits. A second run produced a byte-identical file.

TASK-6: Added two fixtures and two test files.
- **Fixtures:** `tests/Fixtures/models-dev.json` is a small, unsorted models.dev payload with a non-enum `anthropic` provider, a `deprecated` model, `structured_output: false`, a missing flag and `alpha`/`beta` statuses. `tests/Fixtures/ai-models.json` is a fixed snapshot.
- **`app/Plugins/Ai/Tests/RefreshAiModelsCommandTest.php`** compares the exact output bytes, which covers filtering, enum-only providers, sort order and the `id`/`name`-only shape. It also checks that the committed snapshot is unchanged, that HTTP 500 fails and leaves the file alone, and that a missing provider does the same.
- **`app/Plugins/Ai/Tests/AiModelSnapshotTest.php`** covers the list, `has()` returning true, false for an unknown model and false for a model under another provider, and a provider missing from the file.

All HTTP is faked.

TASK-7: `composer test` (846 tests) and `composer analyse` both exit 0. No baseline entries were added.

## Known issues / follow-ups

- **Environment:**
  - `vendor/` and the ignored `storage/framework/*` directories were missing in the worktree. I ran `composer install` from the lock file (no new packages) and created those directories locally.
  - Every test prints a warning that `/workspace/.env` is missing. This already happened before the change and comes from the environment.
  - My first baseline run showed 2 failures that did not come back in later runs. They look flaky; I did not look into them.
  - `.env.testing` shows as modified because it is mounted as a character device in this sandbox. It was not touched and cannot be committed.
- **`user-plugins-storage`:** needs a display name per provider (`"name": "OpenAI"`). It can add `AiProviderEnum::label()`.
- **`ai-plugin`:**
  - Some OpenAI models in the snapshot may only work through the Responses API (e.g. `*-pro`, codex), not chat completions.
  - OpenRouter recommends `provider.require_parameters: true` so that `response_format` is honoured.
- **Docs:** `docs/STANDARDS.md` rule 9.3 and the layout table in `docs/TESTING.md` don't mention `app/Plugins` yet. I didn't edit them because that is out of scope.
