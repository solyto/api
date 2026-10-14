# Backlog: AI providers and model snapshot

created: 2026-10-14

## What
The curated list of AI providers and models a user can pick for the AI plugin. Nothing reads it yet. `user-plugins-storage` (validation) and `ai-plugin` (requests) use it.

1. **Providers:** a backed enum with the three providers and a fixed base URL each. Verify every URL against the provider's current docs:
   - `openai` → `https://api.openai.com/v1`
   - `openrouter` → `https://openrouter.ai/api/v1`
   - `mistral` → `https://api.mistral.ai/v1`

   Before adding a provider, confirm in its docs that its OpenAI-compatible chat completions endpoint supports `response_format` with `type: json_schema`. `LibraryRecommender` relies on that (`respondStructured`).

2. **Snapshot command:** an artisan command (e.g. `ai:refresh-models`) that:
   - downloads `https://models.dev/api.json`
   - for each provider in the enum, takes the models under the same key in models.dev (`openai`, `openrouter`, `mistral`)
   - keeps only models with `structured_output: true` and `status` not `deprecated`
   - writes the result to a committed file, sorted by provider and model ID so diffs stay stable. Per model: `id`, `name`.

   The command is run by a developer, never by the scheduler. Register it per rule 9.3.

3. **Reading it:** a small way to ask "is `<model>` available for `<provider>`?" and "which models does `<provider>` offer?", reading only the committed file.

4. **Tests:** the command with `Http::fake()` and a small fixture of models.dev data. Check the filtering (`structured_output`, `deprecated`), that only enum providers are written, and the stable order. Also test the lookup against a fixed snapshot.

## Why
Owner decisions 2026-10-14:
- Users pick a **provider** and a **model** from curated lists, not free-text URLs. That avoids SSRF: the server only calls base URLs from our code.
- Model data comes from **models.dev** (MIT, `structured_output` and `status` flags), but as a **committed snapshot**, not fetched at runtime. Self-hosted instances make no outbound call, and every list change is a reviewed diff.
- **First providers: OpenAI, OpenRouter, Mistral.** Claude and DeepSeek models come through OpenRouter. Anthropic's OpenAI-compatible endpoint ignores `response_format`, and DeepSeek documents only `json_object`. Direct providers for them would need a second client and are a possible later step.
- No new packages. All three go through the existing `openai-php/client` with a base URL.

## Out of scope
- Plugin storage, validation and endpoints (`user-plugins-storage`).
- Changing `AiService` (`ai-plugin`).
- Direct Anthropic or DeepSeek providers.

The snapshot is a JSON file, `resources/data/ai-models.json` (owner, 2026-10-14). It's data, not generated PHP: nothing built from external data is ever executed. The app reads it with `json_decode`.

No further narrowing (owner, 2026-10-14). The snapshot keeps every model that passes the two filters, about 340 today (OpenRouter 302, OpenAI 31, Mistral 8). The API exposes all of them, and the frontend groups them, e.g. OpenRouter models by the vendor prefix of their ID (`anthropic/`, `deepseek/`, ...). The curation is the reviewed diff on each refresh.

## Where it lives
Owner decision 2026-10-14: plugins live in `app/Plugins/`, a peer of `app/Api/` like `app/Bots/` and `app/Dav/` (rule 12.1). Everything in this entry goes into `app/Plugins/Ai/`: the provider enum (`AiProviderEnum`), the snapshot command (`app/Plugins/Ai/Commands/`) and the snapshot reader. Register the command directory in `bootstrap/app.php` `withCommands` (rule 9.3). The test and PHPStan setup for `app/Plugins` is described in `user-plugins-storage`. If this entry runs first, it does that setup.

## Notes
Check: `composer test` and `composer analyse` exit 0.
