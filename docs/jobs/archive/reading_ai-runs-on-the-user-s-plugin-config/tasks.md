# Tasks: AI runs on the user's plugin config

id: reading
analyst: @analyst
date: 2026-10-14

<!-- Produced by @analyst from brief.md. -->

## Context checked

- Branch: worktree HEAD is `feature/reading_ai-runs-on-the-user-s-plugin-config`, which matches `brief.md`.
- The dependencies are already merged: `remove-ai-usage` (AiService has no usage tracking any more), `user-plugins-storage` (`app/Plugins/{Models/UserPlugin,Services/UserPluginService,Factories/UserPluginFactory}.php`), and `ai-provider-model-snapshot` (`app/Plugins/Ai/{Enums/AiProviderEnum,Services/AiModelSnapshot}.php`).
- Some line numbers in the brief have moved since it was written:
  - `AiService` constructor: `:13-25`, not `:20-31`.
  - `LibraryRecommender::new()`: `:101-126`.
  - `ai` block in `config/services.php`: `:42-46`, not `:46-50`.
  - `tests/TestCase.php`: comment `:72-76`, `app.key` `:77`, stub `:78`. These still match.
- `AiService` is used only by `LibraryRecommender::new()` (`respondStructured`) and by tests. `respond()` has no production caller, but it stays (removing it is out of scope).
- `UserPluginService` (`app/Plugins/Services/UserPluginService.php`) already has the logic AI needs, but as private methods: `row()`, `apiKeyStored()`/`hasReadableCredentials()` (safe against keys written under a lost APP_KEY), and `aiModelAvailable()`. The only write path for plugins is `UserPluginService::update()` (`PUT users/me/plugins/{plugin}`). There is no destroy endpoint.
- The recommendation cache keys are `['book_recommendations', $userId]` (`LibraryBookService.php:21`) and `['music_recommendations', $userId]` (`LibraryMusicService.php:22`), both via `UserCacheService`. The codebase already repeats cache-key constants in the class that forgets them (`CacheInvalidationPlugin.php:15-18` repeats `CalendarService`/`ContactService` keys), so there is a precedent for doing the same here.
- `phpunit.xml` already has a `Plugins` suite covering `app/Plugins/*/Tests`.
- The test helpers `fakeChatResponse()` / `mockedOpenAiClient()` are global functions in `LibraryServiceTest.php`. Only the `AiService` tests use them, so they move with those tests. If they stayed in both files, PHP would fail with a "cannot redeclare" fatal error.

## Task breakdown

TASK-1: Move `AiService` to `app/Plugins/Ai/Services/AiService.php` (namespace `App\Plugins\Ai\Services`) with no behaviour change, and update every import.
  files: app/Api/Libraries/Services/AiService.php (delete), app/Plugins/Ai/Services/AiService.php (new), app/Api/Libraries/Services/LibraryRecommender.php (add `use`, since it is no longer in the same namespace), app/Api/Libraries/Tests/LibraryServiceTest.php (import)
  depends: none
  risk: low. It is a pure move with a namespace change, and the only consumer is `LibraryRecommender` (auto-wired by the container).
  notes: The brief says "`app/Plugins/Ai/`". `Services/` matches the sibling `AiModelSnapshot` and the module layout. Keep `git mv` so history follows.

TASK-2: Move the three `AiService` tests and the `fakeChatResponse()` / `mockedOpenAiClient()` helpers out of `LibraryServiceTest.php` into a new `app/Plugins/Ai/Tests/AiServiceTest.php`. The `LibraryRecommender` and `LibraryReleases` describe blocks stay in Libraries.
  files: app/Api/Libraries/Tests/LibraryServiceTest.php, app/Plugins/Ai/Tests/AiServiceTest.php (new)
  depends: TASK-1
  risk: low. Test-only. The one trap is the helper redeclaration noted above.
  notes: Do this as a move first (the tests stay green against the unchanged API). TASK-3 then adapts them.

TASK-3: Make `AiService` build its OpenAI client per call from the user's AI `UserPlugin`. `respond()` / `respondStructured()` take `User $user` as their first argument (rule 4.1). The base URL comes from `AiProviderEnum::baseUrl()`, the key from the decrypted `credentials['api_key']`, and the model from `settings['model']`. Remove every `config('services.ai.*')` read.
  files: app/Plugins/Ai/Services/AiService.php, app/Plugins/Services/UserPluginService.php, app/Plugins/Ai/Tests/AiServiceTest.php
  depends: TASK-1, TASK-2
  risk: medium. It changes the public method signatures, needs a small new public lookup on `UserPluginService`, and the decrypt-failure path must not throw.
  notes:
    - Put the lookup in `UserPluginService`, because that is where the private helpers already live. For example, add a public method that returns the usable AI config (`provider`, `api_key`, `model`) or `null`. It returns `null` when the row is missing, `enabled` is false, the key is missing or unreadable (reuse `hasReadableCredentials`), or `model_available` is false (reuse `aiModelAvailable`). Inject `UserPluginService` into `AiService`'s constructor (rule 4.2). Do not duplicate the checks in `AiService`.
    - The unavailable path: `respondStructured()` (and `respond()`) return `null` without building a client or making a request. Return types become nullable (`?array` / `?string`). An alternative is a separate `available(User)` check that the recommender calls. Either works; pick one and make it consistent across both methods.
    - Keep the `?ClientContract $client = null` constructor seam. If one is injected, use it instead of the one built from the plugin, but only after the availability check passes. That way the "no request" tests can assert `shouldNotReceive('chat')` on the injected mock.
    - **Open point (test seam for URL/key):** an injected `ClientContract` replaces the built client completely, so it cannot prove "built with the user's provider URL and key". The model can be asserted on the `create()` payload. For URL and key, the smallest option without new packages is an optional PSR-18 HTTP client passed to `OpenAI::factory()->withHttpClient()`. Guzzle is already a transitive dependency of Laravel, so its `MockHandler` plus history middleware can capture the request and the test can assert its URI host/path and `Authorization: Bearer …` header. That adds a second constructor seam. The alternative is to build the factory in one small method and assert on that. Choose the less intrusive option and note the choice in implementation.md for the reviewer. Do not add a new factory class or interface (no new abstractions).
    - Never log or put the API key in an exception message (hard rule on secrets).

TASK-4: Make `LibraryRecommender::new()` pass the user to `AiService::respondStructured()` and return `null` when AI is unavailable. The `NEW` response then has the same shape as the existing "no favorites" case (rule 13.1).
  files: app/Api/Libraries/Services/LibraryRecommender.php, app/Api/Libraries/Tests/LibraryServiceTest.php
  depends: TASK-3
  risk: low. `LibraryBookService` / `LibraryMusicService::recommendNew` already handle a `null` result (`$result['recommendations'] ?? []` → `array_shift([])` → `null`), so the controller path does not change.
  notes: Update the existing `new() calls the AI service…` test so the mock expectation includes the user argument. Also update its stale "and saves usage" name, but only if you are already editing that line. The recommender tests keep mocking `AiService`.

TASK-5: Forget both recommendation cache keys for the user whenever `UserPluginService::update()` saves the AI plugin (rule 8.2), so stale AI results stop showing after the plugin is changed or disabled.
  files: app/Plugins/Services/UserPluginService.php
  depends: none (can go in parallel with TASK-3; both edit `UserPluginService`, so do them one after the other to avoid conflicts)
  risk: medium. It makes `UserPluginService` (Plugins) know Libraries' cache key names, which couples the two modules. A missed key means stale recommendations.
  notes: Inject `UserCacheService`. Forget `['book_recommendations', $user->id]` and `['music_recommendations', $user->id]` through `private const string` keys on `UserPluginService`, following the `CacheInvalidationPlugin` precedent. Forget them on every AI update, not only on real changes. That is simpler and the cost is one AI call. Do not make the Libraries constants public or add an event/listener (no new abstractions). If the reviewer would prefer a cross-module call instead, flag it rather than redesigning.

TASK-6: Remove the instance AI config: the `ai` block in `config/services.php:42-46`, and the `services.ai.api_key` stub in `tests/TestCase.php:78`. Reword the comment at `:72-76` so it only explains the fixed app key. Keep the `app.key` line `:77`.
  files: config/services.php, tests/TestCase.php
  depends: TASK-3 (nothing may still read `services.ai.*`)
  risk: low. Config and tests only. The `AI_API_KEY` Docker secret itself lives in the solyto/deployment repo and is out of scope.
  notes: Do not touch the `solyto_ai` block above it. It is a different, unrelated config, and the `services.ai` grep does not match it.

TASK-7: Update `docs/TESTING.md:50-52` so it no longer mentions the `services.ai.api_key` stub. It should say only that `app.key` is a fixed test key, and that `AiService` is mocked or given an injected client.
  files: docs/TESTING.md
  depends: TASK-6
  risk: low. Docs only.
  notes: This is not listed in the brief, but the brief's "grep for `services.ai` → no hits" check would otherwise fail on this live doc. Hits under `docs/jobs/archive/**`, `docs/reports/**`, and this job's own `brief.md` are historical records and should stay. Please confirm with the owner that this reading of "no hits" is right; I treated it as code plus current docs.

TASK-8: Add the tests from brief §6.
  files: app/Plugins/Ai/Tests/AiServiceTest.php, app/Api/Libraries/Tests/LibraryServiceTest.php and/or app/Api/Libraries/Tests/LibraryApiTest.php, app/Plugins/Tests/UserPluginApiTest.php (or a service-level test)
  depends: TASK-3, TASK-4, TASK-5
  risk: low. Test-only, and `UserPluginFactory` already produces an enabled row with a key and an available model.
  notes:
    - AiService: with a plugin row (factory), the request uses the provider's base URL, the user's key, and the stored model (via the seam chosen in TASK-3).
    - AiService: no request (the mock's `chat()` is never called) and a `null` return when (a) there is no row, (b) `enabled=false`, (c) there is no or an unreadable key, (d) the model is not in the snapshot.
    - `NEW` returns `null` in those cases. Run at least one case end to end through `GET /api/v1/libraries/books/recommend/new` (and music) with favorites present, and assert the same response as the existing no-favorites path. Check what that path returns today (likely 404, like `random`) and assert exactly that.
    - Cache: seed `book_recommendations` / `music_recommendations` for the user, call `PUT /api/v1/users/me/plugins/ai` (for example to disable it), and assert both keys are gone.
    - Run `composer test` and `composer analyse`. Both must exit 0. Then grep `services.ai` and check there are no hits outside the historical docs (see TASK-7).

## Report reminders (for implementation.md / the job report)

- Owner: remove the `AI_API_KEY` Docker secret (and `AI_BASE_URL` / `AI_MODEL` env) from the solyto/deployment repo.
- Owner: announce, outside the code, that instance-key AI recommendations stop until users configure the AI plugin.

## Unclear / flagged

- The test seam for the URL/key assertion (TASK-3) is an implementation choice that the brief does not settle.
- The scope of "no `services.ai` hits" in docs (TASK-7).
- Plugins → Libraries cache-key coupling (TASK-5) follows an existing precedent, but it is a new cross-module dependency direction.
