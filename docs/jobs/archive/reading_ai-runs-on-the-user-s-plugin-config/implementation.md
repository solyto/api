# Implementation: AI runs on the user's plugin config

id: reading
developer: @developer
date: 2026-10-14

## Summary

`AiService` now lives in `app/Plugins/Ai/Services/`. It builds its OpenAI client per call from the user's AI `UserPlugin` row: the base URL comes from the provider enum, the key from the decrypted credentials, and the model from settings. When the plugin is not usable, it makes no request and returns `null`, so `LibraryRecommender::new()` returns `null` and the `NEW` endpoints answer with the same 404 as the existing "no favorites" case. Saving the AI plugin forgets the user's book and music recommendation caches. The instance AI config (`services.ai`) and its test stub are removed.

`composer test` exits 0 (all tests pass) and `composer analyse` exits 0 (no errors). `grep services.ai` finds no hits in code or current docs; the remaining hits are in historical job records (`docs/jobs/**`), as noted in the tasks.

## Changes

TASK-1: Moved `app/Api/Libraries/Services/AiService.php` → `app/Plugins/Ai/Services/AiService.php` (namespace `App\Plugins\Ai\Services`), with no behaviour change. Added the `use` in `LibraryRecommender` and updated the import in `LibraryServiceTest`. The git shim refuses `git mv`, so this was a plain `mv`. Git still detects it as a rename at commit level.

TASK-2: Moved the three `AiService` tests and the `fakeChatResponse()` / `mockedOpenAiClient()` helpers from `app/Api/Libraries/Tests/LibraryServiceTest.php` into the new `app/Plugins/Ai/Tests/AiServiceTest.php`. The `LibraryRecommender` and `LibraryReleases` tests stay in Libraries.

TASK-3:
- `UserPluginService::aiConfig(User): ?array{provider, api_key, model}` is the new public lookup. It reuses the existing private checks (`row`, `apiKeyStored`/`hasReadableCredentials`, `aiModelAvailable`). It returns `null` when the row is missing, the plugin is disabled, the key is missing, empty or unreadable, or the model is not in the snapshot.
- `AiService::respond()` / `respondStructured()` now take `User $user` first and return `?string` / `?array`. They return `null` before building a client when `aiConfig()` is `null`. No `config('services.ai.*')` reads remain.
- Constructor: `UserPluginService` (rule 4.2), the kept `?ClientContract $client` seam (used in place of the built client, but only after the availability check), and a new optional `?Psr\Http\Client\ClientInterface $httpClient` passed to `OpenAI::factory()->withHttpClient()`.
- `LibraryRecommender::new()` passes `$user` through (this was needed for the signature change, so it went in this commit).
- **Test-seam choice (for the reviewer):** I used the PSR-18 HTTP-client seam, not a factory method. It lets the test send a real OpenAI client through a Guzzle `MockHandler` + history middleware, then assert the actual URI, `Authorization` header and body model. Guzzle is already present (Laravel dependency), so no packages were added. The container resolves both optional interface params to `null` (verified in tinker), so production behaviour is unaffected.
- The API key is never logged and never put in an exception message.

TASK-4: Updated the recommender test so it asserts that the user and the favorites message are passed to `respondStructured` (and renamed the stale "saves usage" test). Added a test that `new()` returns `null` when `AiService` returns `null`. `LibraryBookService` / `LibraryMusicService` already map a `null` result to "no recommendation" (404), so no production change was needed there.

TASK-5: `UserPluginService::update()` forgets `book_recommendations` and `music_recommendations` for the user on every AI-plugin save. It does this through `private const string` keys, which repeat the Libraries key names (the `CacheInvalidationPlugin` precedent), and a `match ($plugin)` → `forgetAiRecommendations()`. The `match` was needed because, with a single enum case, an `if ($plugin === PluginEnum::AI)` fails PHPStan (`identical.alwaysTrue`). That fix went in with the TASK-8 commit.

TASK-6: Removed the `ai` block from `config/services.php` and the `services.ai.api_key` stub from `tests/TestCase.php`. Reworded the comment so it only covers the app key. The `app.key` line is unchanged, and the unrelated `solyto_ai` block is untouched.

TASK-7: `docs/TESTING.md` now says only that `app.key` is a fixed test key, and that `AiService` is mocked or given an injected OpenAI or PSR-18 client.

TASK-8: Tests:
- `AiServiceTest`: a request goes to `https://openrouter.ai/api/v1/chat/completions` with `Bearer <user key>` and the user's model. A dataset of six unavailable cases (no row, disabled, null key, empty key, key encrypted under another APP_KEY, model not in the snapshot) checks that no `chat()` call happens and that both methods return `null`.
- `LibraryApiTest`: `GET /libraries/{books,music}/recommend/new` with a favorite present, and with either no plugin or a disabled one, returns the same 404 envelope as the no-favorites case. The comparison ignores `timestamp`.
- `UserPluginApiTest`: `PUT /users/me/plugins/ai` (disable, or change model) forgets both recommendation keys for that user only; another user's cache is untouched. I checked this test by mutation: with the forget lines removed, it fails.

## Known issues / follow-ups

- **Owner (deployment):** remove the `AI_API_KEY` Docker secret and the `AI_BASE_URL` / `AI_MODEL` env from the solyto/deployment repo. Nothing in this repo reads them any more.
- **Owner (announcement):** when this ships, announce outside the code (changelog, release notes or a post) that AI recommendations through the instance key stop until users configure the AI plugin.
- `UserPluginService` (Plugins) now knows the Libraries recommendation cache key names, a new Plugins → Libraries coupling. It follows the `CacheInvalidationPlugin` precedent; flagged as the analyst asked.
- "No `services.ai` hits" was read as code plus current docs. Historical records under `docs/jobs/**` still mention it and were left as is (owner to confirm, per TASK-7).
- Environment notes, not code: the worktree had no `vendor/`, so I ran `composer install` from the lock file (nothing new added). I also created the git-ignored `storage/framework/*` directories so artisan can boot. Every test prints a "warning" because there is no `.env` file and `.env.testing` is mounted as `/dev/null`. That is unrelated to this job, and the tests themselves pass.
