# Backlog: AI runs on the user's plugin config

created: 2026-10-14

## What
Switch AI from the instance key to each user's AI plugin, and remove the instance AI config.

1. **Move `AiService`** from `app/Api/Libraries/Services/AiService.php` to `app/Plugins/Ai/` (owner, 2026-10-14; see Where it lives in `user-plugins-storage`). Update the imports in `LibraryRecommender` and the tests. Move the `AiService` tests out of `app/Api/Libraries/Tests/LibraryServiceTest.php` into `app/Plugins/Ai/Tests/`. The `LibraryRecommender` tests stay in Libraries.
2. **Configure it per user:** stop reading `config('services.ai.*')` in the constructor (`:20-31`). Build the OpenAI client per call from the user's `UserPlugin` row: base URL from the provider enum, `api_key` from the decrypted credentials, `model` from settings. The user comes in as a method argument, not via the constructor (rule 4.1). Keep the `ClientContract` injection seam for tests.
3. **When AI is unavailable:** if the plugin is missing, `enabled` is `false`, there is no key, or the model is not in the snapshot (`model_available` false), no request is made. `LibraryRecommender::new()` (`app/Api/Libraries/Services/LibraryRecommender.php:102`) returns `null`, the same as when the user has no favorites today. So the `NEW` recommendation responses keep their current shape for every client (rule 13.1).
4. **Cache:** `LibraryBookService::recommendNew` / `LibraryMusicService::recommendNew` cache recommendations per user (`CACHE_KEY_RECOMMENDATIONS`). Changing or disabling the AI plugin must forget those keys, so old AI results don't keep showing (rule 8.2).
5. **Remove instance AI config:** the `ai` block in `config/services.php:46-50` (`AI_API_KEY` via `DockerSecretHelper`, `AI_BASE_URL`, `AI_MODEL`), and the `services.ai.api_key` stub in `tests/TestCase.php:78`. Reword the comment above it (`:72-76`) so it only talks about the app key. The `app.key` line (`:77`) stays.
6. **Tests:**
   - the client is built with the user's provider URL, key and model
   - no request when the plugin is off, has no key, or has an unavailable model
   - `NEW` returns `null` in those cases
   - the recommendation cache is cleared when the plugin changes

## Why
Owner decision 2026-10-14: AI is strictly a **plugin**. Each user configures provider, key and model; there is no global AI key, on solyto.app either. Integrations (instance-wide) and plugins (per user) don't overlap.

## Out of scope
- Plugin storage and endpoints (`user-plugins-storage`), the provider/model list (`ai-provider-model-snapshot`), usage tracking (`remove-ai-usage`).
- New AI features.
- Removing the `AI_API_KEY` Docker secret from the deployment. That lives in the separate solyto/deployment repo; note it in the job's report for the owner.

## Announcement
Users on solyto.app who get AI recommendations through the instance key today lose them until they set up the plugin. The owner announces this outside the code (changelog, release notes or a post; owner, 2026-10-14). No in-app notification. The job's report should remind the owner to do it when this ships.

## Notes
Depends on `remove-ai-usage`, `remove-user-openai-key`, `user-plugins-storage` and `ai-provider-model-snapshot`.

Grep for `services.ai` afterwards. No hits should remain.

Check: `composer test` and `composer analyse` exit 0.
