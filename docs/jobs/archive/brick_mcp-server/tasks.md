# Tasks: mcp server

id: brick
analyst: @analyst
date: 2026-10-17

<!-- Produced by @analyst from brief.md. -->

## Context checked

- Branch: worktree HEAD is `feature/brick_mcp-server`, which matches `brief.md`.
- Routes: all `/api/v1` routes are in `routes/api.php`. The public group has `health` and 7 `auth/*` routes. Everything else sits in the `auth:sanctum` + `TrackApiTraffic` group. The `api` middleware group also gets `RecordClientVersion` (`bootstrap/app.php:34`).
- Exceptions on `api/*` (or `expectsJson()`) are rendered into the `ApiResponse` envelope by `bootstrap/app.php`. So a sub-request to `/api/v1/...` already returns envelope JSON for 401/403/404/422/etc. `call_api` only has to relay it.
- Tokens: `AuthService::createToken()` always sets an expiry and names tokens `auth-token-{platform}`. Endpoints that touch the *current* token:
  - `auth/logout` deletes the current token.
  - `auth/logout-all` deletes all of the user's tokens.
  - `auth/refresh` **deletes the current token** and issues a new one, so through MCP it would kill the MCP token.
  - `auth/revoke-token` refuses to revoke the current token but can revoke any other token.
- `config/sanctum.php` has `expiration => null`, so a token created with `expiresAt: null` never expires.
- OpenAPI: the spec is annotation-only. `storage/api-docs/` does not exist in the repo (it's generated), so `list_endpoints` cannot rely on the generated JSON. It has to read `@OA\Get/Post/...` `summary="..."` from the controller method docblocks (reflection) and fall back to `Controller@method` when there is no annotation (e.g. `forgotPassword`, `resetPassword`).
- Commands live in modules and are registered by directory in `bootstrap/app.php` `withCommands([...])`. Existing signatures use the `app:` prefix (`app:users:create`). `laravel/mcp` itself ships `mcp:*` / `make:mcp-*` commands.
- Tests: `app/Api/*/Tests` is the `Api` suite and gets `TestCase` + `RefreshDatabase` from `tests/Pest.php`. The helpers `makeUser()`, `sanctumToken()` and `authHeaders()` already exist.
- laravel/mcp (Laravel 12 docs):
  - Install, then `php artisan vendor:publish --tag=ai-routes` creates `routes/ai.php`.
  - Servers are registered with `Mcp::web('/mcp', Server::class)->middleware('auth:sanctum')`.
  - Tools extend `Laravel\Mcp\Server\Tool` with `schema(JsonSchema)` and `handle(Request): Response`. Inside a tool, `$request->user()` is the authenticated user.
  - Responses are `Response::text()/error()`, plus `Response::make(...)->withStructuredContent()`.
  - Test helpers: `Server::actingAs($user)->tool(Tool::class, [...])` with `assertOk/assertSee/assertHasErrors`.
  - `php artisan mcp:inspector mcp` opens the inspector.

## Unclear points / assumptions (please confirm; not guessed silently)

1. **The brief's Notes section is garbled** (text is missing mid-sentence in lines 35–39). What I could read and what I assume:
   - Location: "small and self-contained (e.g. `app/Api/Mcp/` or `app/Mcp/`)". **Assumption: `app/Api/Mcp/`.** Tests in `app/Api/Mcp/Tests` then join the existing `Api` suite and get `RefreshDatabase` without touching `phpunit.xml`/`tests/Pest.php`. This matches rule 12.1.
   - "`call_api` should only accept…": the rest of the sentence is lost. **Assumption: only relative paths under `/api/v1`.** Reject absolute URLs, other hosts, `..` segments and anything outside the `v1` prefix (including `/mcp` itself, `/dav`, `/webhooks`).
   - "must not be usable to revoke the MCP token itself or log the user out of everything (e.g. block or warn on `logout`/`logout-all`/token ends…)". **Assumption: hard block (refuse with a tool error) for `POST auth/logout`, `auth/logout-all`, `auth/refresh` and `auth/revoke-token`.** Refresh is on the list because it deletes the current (MCP) token. I'd also block the public `auth/*` routes (login/register/verify/password reset/passkey authenticate), since the agent has no business there. `GET auth/tokens` and passkey management stay allowed. If the owner wanted "warn" instead of "block", only TASK-5 changes.
   - "Record it as a decision/ed`…" probably means "record it as a decision in `docs/STANDARDS.md` `## Decisions`" (the JSON-RPC envelope exception). Assumed so in TASK-9.
   - The README line is cut off before the token placeholder. Assumed: a short README section (flag, token command, `claude mcp add …` example with `Authorization: Bearer <token>`).
2. **Route file.** Rule 12.2 says "one route file" for the API. `laravel/mcp` uses `routes/ai.php`. **Assumption: use `routes/ai.php` as the package intends**, and record it as a Decision next to the envelope exception. Please confirm. The alternative is registering `Mcp::web` from a provider.
3. **Command name.** The brief suggests `mcp:token`. That namespace belongs to the package (`mcp:inspector`, `mcp:start`). **Assumption: `app:mcp:token {email}`**, following the `app:users:*` convention.
4. **Config key.** **Assumption: `services.mcp.enabled` ← `env('MCP_ENABLED', false)`** in `config/services.php`. This avoids clashing with any `config/mcp.php` the package may publish. If the package does not publish one, a small `config/mcp.php` would also be fine.

## Task breakdown

TASK-1: Install `laravel/mcp` (^1.0, explicitly approved in the brief), publish `routes/ai.php` (`vendor:publish --tag=ai-routes`) and check that `composer test` / `composer analyse` are still green with the empty route file.
     files: composer.json, composer.lock, routes/ai.php (new, empty server list)
     depends: none
     risk: medium — a new dependency. Check that it supports Laravel 12 / PHP 8.4, that its provider auto-loads `routes/ai.php` (the next tasks rely on this), and whether it publishes config or adds Larastan errors.

TASK-2: Add the off-by-default flag: `services.mcp.enabled` read from `MCP_ENABLED` (default `false`), plus `MCP_ENABLED=false` in `.env.example` if that file exists (it is not in the repo today, so skip if absent).
     files: config/services.php
     depends: none
     risk: low — a config line, and nothing reads it yet.

TASK-3: Create the MCP server class and register it in `routes/ai.php` as `Mcp::web('/mcp', SolytoServer::class)->middleware('auth:sanctum')` (plus a throttle if a named limiter fits). The registration only happens when `config('services.mcp.enabled')` is true. With no tools yet, the route returns an empty tool list.
     files: app/Api/Mcp/Servers/SolytoServer.php (new), routes/ai.php
     depends: TASK-1, TASK-2
     risk: medium — the flag is read when routes are registered. Tests that flip the flag must re-register routes (or boot the app with the env set), and the route must not end up in the cached route list when it's off. The developer must also check that the `/mcp` route is not in the `web` group (CSRF/session) and that an unauthenticated call gets a 401 (`expectsJson`) rather than a redirect to a `login` route that doesn't exist.

TASK-4: Implement the `list_endpoints` tool. Read `Route::getRoutes()` and keep only URIs under `api/v1`. Return method, path (without the `api/v1` prefix) and a short description, plus whether auth is required. The description comes from the controller method's `@OA\<Verb>` `summary` (reflection on the docblock) and falls back to `Controller@method`. Takes an optional case-insensitive `filter` over method/path/description. `HEAD` and `OPTIONS` are dropped. Endpoints that `call_api` blocks are either left out or marked as blocked.
     files: app/Api/Mcp/Tools/ListEndpointsTool.php (new), app/Api/Mcp/Servers/SolytoServer.php
     depends: TASK-3
     risk: low — read-only. The only fragile part is the docblock `summary` parsing, and it has a fallback.

TASK-5: Implement the `call_api` tool. Inputs are `method` (GET/POST/PUT/PATCH/DELETE), `path`, optional `query` (object) and `body` (object).
     - Validate the path: relative, under `/api/v1` only, no scheme/host/`..`.
     - Refuse the blocked auth endpoints from assumption 1 with a tool error.
     - Build an internal sub-request to `/api/v1/{path}` with JSON headers, and forward the incoming MCP request's `Authorization: Bearer` header so Sanctum resolves the same token and user.
     - Dispatch it through the HTTP kernel so routing, middleware, FormRequest validation and `HandlesApiAuth` ownership checks run unchanged.
     - Restore the original `request` binding afterwards.
     - Return the status code plus the decoded JSON body (including 4xx/5xx envelopes) as the tool result. Non-JSON responses (cover images, export download) get a short placeholder text, not raw bytes.
     files: app/Api/Mcp/Tools/CallApiTool.php (new), app/Api/Mcp/Servers/SolytoServer.php
     depends: TASK-3
     risk: high — this is the security boundary. Points the developer must verify by test:
       - a sub-request inside a request can leak state: the swapped `request` instance, and the sanctum `RequestGuard` cached user;
       - the user must come from the forwarded token, never from the request payload;
       - path validation must not be bypassable (encoded `%2e%2e`, double slashes, absolute URLs);
       - it must not duplicate any business logic.

TASK-6: Add the `app:mcp:token {email}` command in the module.
     - Look up the user by email; if missing, fail with a clear error.
     - Create a Sanctum token named e.g. `mcp-token` with `expiresAt: null` via `$user->createToken(...)`.
     - Print the plain-text token once, with a warning that it isn't shown again. Never log it.
     - Register `app/Api/Mcp/Commands` in `bootstrap/app.php` `withCommands([...])`.
     files: app/Api/Mcp/Commands/CreateMcpToken.php (new), bootstrap/app.php
     depends: none (only Sanctum, already installed)
     risk: low — small command following the `app/Api/Users/Commands` shape. Token creation is already a Sanctum primitive. The point to verify: the name must not start with `auth-token-` (`AuthService::refreshToken` parses that prefix).

TASK-7: Pest tests, routing and auth (`app/Api/Mcp/Tests/McpServerTest.php`):
     - flag off: `/mcp` is not registered (404);
     - flag on, no token: 401;
     - flag on, valid token: `tools/list` returns `list_endpoints` and `call_api`.
     These go through real JSON-RPC `postJson('/mcp', …)` with `authHeaders()`.
     files: app/Api/Mcp/Tests/McpServerTest.php (new)
     depends: TASK-3
     risk: medium — the route is registered at boot based on the flag, so the test needs a reliable way to boot with the flag on/off. Do not leak a re-registered route into other tests.

TASK-8: Pest tests, the tools (`app/Api/Mcp/Tests/ListEndpointsToolTest.php`, `CallApiToolTest.php`, `CreateMcpTokenTest.php`):
     - `list_endpoints` returns real routes (e.g. `GET todos` with its OA summary) and the filter narrows the list.
     - `call_api` acts as the token's user: create a todo, then list it.
     - Another user's todo/note returns the API's 403/404 envelope.
     - A validation error returns the 422 envelope with `errors`.
     - An unknown path returns 404. Blocked endpoints (`auth/logout`, `logout-all`, `refresh`, `revoke-token`) are refused, and the MCP token still works afterwards.
     - Paths outside `/api/v1` and absolute URLs are rejected.
     - The command creates a non-expiring, correctly named token, prints it, and fails for an unknown email.
     Run `call_api` tests over HTTP with a real bearer token rather than only `Server::actingAs()`, because the tool forwards the Authorization header.
     files: app/Api/Mcp/Tests/ListEndpointsToolTest.php, app/Api/Mcp/Tests/CallApiToolTest.php, app/Api/Mcp/Tests/CreateMcpTokenTest.php (all new)
     depends: TASK-4, TASK-5, TASK-6, TASK-7
     risk: medium — these tests are the evidence for the security claims in the brief (no cross-user access, MCP token can't be self-revoked). They must exercise the real middleware stack, not mocks.

TASK-9: Documentation:
     - a short README section: enable `MCP_ENABLED=true`, run `php artisan app:mcp:token you@example.com`, then `claude mcp add --transport http solyto http://localhost:8000/mcp --header "Authorization: Bearer <token>"`, and `php artisan mcp:inspector mcp` for debugging. Mark it experimental and local-only.
     - `docs/STANDARDS.md` `## Decisions` entries:
       - (a) MCP responses are JSON-RPC and don't use the `ApiResponse` envelope; the relayed API payload inside them does.
       - (b) `routes/ai.php` is the one exception to "one route file" (rule 12.2).
       - (c) The MCP proxy reuses the HTTP stack instead of `IntegrationGateway` (rule 12.8), because the point is to avoid duplicated logic.
     files: README.md, docs/STANDARDS.md
     depends: TASK-5, TASK-6
     risk: low — docs only. The STANDARDS edits should stay limited to the Decisions list.

TASK-10: Final check: `composer test` and `composer analyse` both exit 0. Add any new Larastan findings in `app/Api/Mcp` as fixes, not to the baseline.
     files: possibly phpstan-baseline.neon (should stay unchanged)
     depends: TASK-1 … TASK-9
     risk: low — verification only. Don't fix unrelated failures.

## Out of scope (from the brief, restated so reviewers can check)

- No OAuth/Passport, no `Mcp::oauthRoutes()`, no claude.ai/ChatGPT connectors, no stdio/`Mcp::local`, no resources/prompts, no UI.
- No migrations, no changes to the DAV server, `pgsql`, `routes/web.php` or existing API behaviour/routes.
- No per-module typed tools.
